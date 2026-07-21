<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Closure;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Public\Dto\ScanCursor;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class BackfillInboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $inboxId,
        public readonly int $windowMonths,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->inboxId;
    }

    public function uniqueFor(): int
    {
        // 30-minute single-flight ceiling: long enough for a
        // multi-page year-of-receipts backfill to finish, short
        // enough that a worker crash unblocks the next dispatch.
        return 1800;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        GmailApiClientContract $gmail,
        GraphApiClientContract $graph,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        KnownSenderQuery $senderQuery,
    ): void {
        $connection = $db->connection();

        $inboxRow = $connection->table('inboxes')->where('id', $this->inboxId)->first();
        if ($inboxRow === null) {
            // Inbox deleted between dispatch and worker pickup —
            // silently exit so the queue doesn't retry forever.
            return;
        }

        $rawUserId = $inboxRow->user_id;
        $userId = is_numeric($rawUserId) ? (int) $rawUserId : 0;
        $rawProvider = $inboxRow->provider;
        $provider = is_string($rawProvider) ? $rawProvider : '';

        /** @var User $user */
        $user = User::query()->where('id', $userId)->firstOrFail();

        // Defensive window clamp — the slider clamps client-side
        // but a crafted POST may carry an out-of-range value.
        $window = max(1, min(12, $this->windowMonths));

        $senderPatterns = array_map(
            static fn ($s) => $s->emailPattern,
            $senderQuery->all($user),
        );

        if ($senderPatterns === []) {
            $sm->applyStatus(
                $this->inboxId,
                'idle',
                'No known senders are configured for this user.',
            );

            return;
        }

        if ($provider === 'gmail') {
            $this->runGmailBackfill(
                $connection,
                $clock,
                $gmail,
                $blobStore,
                $mime,
                $sm,
                $userId,
                $senderPatterns,
                $window,
            );

            return;
        }

        if ($provider === 'microsoft') {
            $this->runMicrosoftBackfill(
                $connection,
                $clock,
                $graph,
                $blobStore,
                $mime,
                $sm,
                $userId,
                $senderPatterns,
                $window,
            );

            return;
        }

        // Unknown provider — surface the misconfiguration without
        // retrying forever. The inboxes row's CHECK trigger pair
        // keeps gmail|microsoft the only legal production values, so
        // this arm is reached only if data bypassed the migration.
        $sm->applyStatus(
            $this->inboxId,
            'error',
            "Unknown provider '{$provider}' — backfill cannot proceed.",
        );
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runGmailBackfill(
        Connection $connection,
        Clock $clock,
        GmailApiClientContract $gmail,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        int $userId,
        array $senderPatterns,
        int $windowMonths,
    ): void {
        $sm->applyStatus($this->inboxId, 'backfilling');

        // Honours the user-selected window: the Gmail after: operator
        // bounds the q= search to this date floor so the walk stops
        // at the slider value instead of racing the full-inbox quota.
        $windowStart = $clock->now()->modify("-{$windowMonths} months")->toDateTimeImmutable();

        // Mutable accumulators captured by the page closure: the
        // running estimate is the max() of per-page hints, and the
        // historyId baseline is the latest non-null reading.
        $accum = new class
        {
            public int $estimated = 0;

            public ?string $highestHistoryId = null;
        };

        try {
            $this->walkAndPersist(
                $connection,
                $clock,
                $blobStore,
                $mime,
                $sm,
                $userId,
                fetchNextPage: function (?string $cursor) use ($gmail, $senderPatterns, $windowStart, $accum): array {
                    $page = $gmail->listSenderMessages($this->inboxId, $senderPatterns, $cursor, $windowStart);
                    $accum->estimated = max($accum->estimated, $page['resultSizeEstimate']);
                    if ($page['historyId'] !== null) {
                        $accum->highestHistoryId = $page['historyId'];
                    }

                    return [
                        'messages' => $page['messages'],
                        'nextCursor' => $page['nextPageToken'],
                        'totalEstimated' => $accum->estimated,
                        'lastMessageDate' => null,
                    ];
                },
                extractMessageId: static fn (array $msgMeta): string => is_string($msgMeta['id'] ?? null) ? $msgMeta['id'] : '',
                fetchRawEml: fn (string $messageId): string => $gmail->getRawMessage($this->inboxId, $messageId),
                extractInternalDate: static fn (array $msgMeta): ?DateTimeImmutable => null,
            );

            // Sets the baseline cursor only via the state machine's
            // sole-mutator surface so BoundaryArchTest stays green.
            if ($accum->highestHistoryId !== null) {
                $sm->recordCursor(
                    $this->inboxId,
                    ScanCursor::gmail($accum->highestHistoryId),
                );
            }

            $this->clearProgressAndIdle($connection, $clock, $sm);
        } catch (RateLimitedException $e) {
            $sm->applyStatus(
                $this->inboxId,
                'rate_limited',
                "Retry after {$e->retryAfterSeconds}s.",
            );
            throw $e;
        } catch (InvalidGrantException $e) {
            $sm->applyStatus(
                $this->inboxId,
                'needs_reauth',
                'OAuth grant revoked or expired.',
            );
            // Do not rethrow — needs_reauth is terminal until the
            // user manually reconnects.
            unset($e);
        } catch (Throwable $e) {
            $sm->applyStatus(
                $this->inboxId,
                'error',
                substr($e->getMessage(), 0, 500),
            );
            throw $e;
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runMicrosoftBackfill(
        Connection $connection,
        Clock $clock,
        GraphApiClientContract $graph,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        int $userId,
        array $senderPatterns,
        int $windowMonths,
    ): void {
        $sm->applyStatus($this->inboxId, 'backfilling');

        // Captures the wall-clock anchor before any provider call so
        // the post-walk deltaPage(null, anchor) baseline uses the
        // pre-walk timestamp, closing the multi-hour-backfill race
        // window (see architecture.md for the full argument).
        $walkStartedAt = $clock->now()->toDateTimeImmutable();
        $windowStart = $walkStartedAt->modify("-{$windowMonths} months");

        // Mutable accumulators captured by the page closure: the
        // running estimate is the max count of messages seen so far,
        // and lastMessageDate is the most recent receivedDateTime.
        $accum = new class
        {
            public int $estimated = 0;

            public ?string $lastMessageDate = null;
        };

        try {
            // Backfill phase: non-delta walk of /me/messages via the
            // (from in [...]) and receivedDateTime ge {start} filter;
            // @odata.nextLink terminates the loop.
            $this->walkAndPersist(
                $connection,
                $clock,
                $blobStore,
                $mime,
                $sm,
                $userId,
                fetchNextPage: function (?string $cursor) use ($graph, $senderPatterns, $windowStart, $accum): array {
                    $page = $graph->listSenderMessagesPaged(
                        $this->inboxId,
                        $senderPatterns,
                        $windowStart,
                        $cursor,
                    );
                    // Graph returns no result-size estimate, so the
                    // progress strip treats the running fetched count
                    // itself as the per-page estimate.
                    $accum->estimated = max($accum->estimated, count($page['messages']));
                    $messages = $page['messages'];
                    if ($messages !== []) {
                        $lastReceived = $messages[count($messages) - 1]['receivedDateTime'] ?? null;
                        if (is_string($lastReceived) && $lastReceived !== '') {
                            $accum->lastMessageDate = $lastReceived;
                        }
                    }

                    return [
                        'messages' => $messages,
                        'nextCursor' => $page['nextLink'],
                        'totalEstimated' => $accum->estimated,
                        'lastMessageDate' => $accum->lastMessageDate,
                    ];
                },
                extractMessageId: static fn (array $msgMeta): string => is_string($msgMeta['id'] ?? null) ? $msgMeta['id'] : '',
                fetchRawEml: fn (string $messageId): string => $graph->getRawMessage($this->inboxId, $messageId),
                extractInternalDate: static function (array $msgMeta): ?DateTimeImmutable {
                    $received = $msgMeta['receivedDateTime'] ?? null;
                    if (! is_string($received) || $received === '') {
                        return null;
                    }
                    try {
                        return new DateTimeImmutable($received);
                    } catch (Throwable) {
                        return null;
                    }
                },
            );

            // Baseline phase: a single delta call after the walk ends
            // and before idle, pinned to the pre-walk anchor so
            // mid-walk messages are captured by the next incremental
            // tick rather than falling into a walk-end/baseline gap.
            $baseline = $graph->deltaPage($this->inboxId, null, $walkStartedAt);
            $deltaLink = $baseline['deltaLink'] ?? null;
            if ($deltaLink !== null && $deltaLink !== '') {
                $sm->recordCursor(
                    $this->inboxId,
                    ScanCursor::microsoft($deltaLink),
                );
            }

            $this->clearProgressAndIdle($connection, $clock, $sm);
        } catch (RateLimitedException $e) {
            $sm->applyStatus(
                $this->inboxId,
                'rate_limited',
                "Retry after {$e->retryAfterSeconds}s.",
            );
            throw $e;
        } catch (InvalidGrantException $e) {
            $sm->applyStatus(
                $this->inboxId,
                'needs_reauth',
                'OAuth grant revoked or expired.',
            );
            unset($e);
        } catch (Throwable $e) {
            $sm->applyStatus(
                $this->inboxId,
                'error',
                substr($e->getMessage(), 0, 500),
            );
            throw $e;
        }
    }

    // Provider-agnostic walker: iterates pages via the closure-based
    // fetcher, writes each message's .eml then DB row (atomic +
    // orphan-cleaned), updates the progress strip per page, and
    // sleeps between pages so the provider quota isn't exhausted.
    /**
     * @param  Closure(?string): array{messages: list<array<string, mixed>>, nextCursor: ?string, totalEstimated: int, lastMessageDate: ?string}  $fetchNextPage
     * @param  Closure(array<string, mixed>): string  $extractMessageId  the provider message id, used as
     *                                                                   both the .eml filename and the unique row key
     * @param  Closure(string): string  $fetchRawEml  the raw RFC 822 byte stream (Gmail
     *                                                decodes base64url for us; Graph returns the bytes verbatim)
     * @param  Closure(array<string, mixed>): ?DateTimeImmutable  $extractInternalDate  the provider-stamped
     *                                                                                  internal_date, or null to fall back to the .eml's Date: header
     *                                                                                  (Gmail's users.messages.list has no per-message receivedDateTime)
     */
    private function walkAndPersist(
        Connection $connection,
        Clock $clock,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        int $userId,
        Closure $fetchNextPage,
        Closure $extractMessageId,
        Closure $fetchRawEml,
        Closure $extractInternalDate,
    ): void {
        $fetched = 0;
        $cursor = null;

        while (true) {
            $page = $fetchNextPage($cursor);
            $messages = $page['messages'];

            if ($messages === []) {
                break;
            }

            foreach ($messages as $msgMeta) {
                $messageId = $extractMessageId($msgMeta);
                if ($messageId === '') {
                    continue;
                }

                // Skip messages already fetched + indexed — both the
                // "extend window" re-run and the cursor-expiry
                // fallback walk can revisit recent messages, and
                // refetching would burn provider quota for nothing.
                $alreadyFetched = $connection->table('inbox_messages')
                    ->where('inbox_id', $this->inboxId)
                    ->where('provider_message_id', $messageId)
                    ->exists();
                if ($alreadyFetched) {
                    continue;
                }

                $rawEml = $fetchRawEml($messageId);

                // Provider-supplied internal date (Microsoft) vs.
                // in-body Date: header (Gmail); when the provider
                // stamps nothing, the fallback goes through the
                // project Clock so test-frozen time stays honoured.
                $internalDate = $extractInternalDate($msgMeta);
                $fallbackDate = $internalDate ?? $clock->now()->toDateTimeImmutable();
                $headers = $mime->parseHeadersWithFallbackDate($rawEml, $fallbackDate);

                $emlPath = $blobStore->pathFor(
                    $userId,
                    $this->inboxId,
                    $headers->internalDate,
                    $messageId,
                );
                $blobStore->put($emlPath, $rawEml);

                try {
                    $connection->transaction(function () use (
                        $connection,
                        $userId,
                        $messageId,
                        $headers,
                        $clock,
                    ): void {
                        $connection->statement('PRAGMA busy_timeout = 5000');
                        $now = $clock->now()->toDateTimeString();
                        $connection->table('inbox_messages')->insertOrIgnore([
                            'user_id' => $userId,
                            'inbox_id' => $this->inboxId,
                            'provider_message_id' => $messageId,
                            'internal_date' => $headers->internalDate->format('Y-m-d H:i:s'),
                            'sender_email' => $headers->senderEmail,
                            'sender_name' => $headers->senderName,
                            'subject' => $headers->subject,
                            'status' => 'fetched',
                            'fetched_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    });
                } catch (Throwable $e) {
                    // Orphan-cleanup: the .eml is on disk but the DB
                    // insert never landed — unlink so there is no
                    // untracked blob.
                    $blobStore->delete($emlPath);
                    throw $e;
                }

                $fetched++;
            }

            // Updates the live progress payload for /inboxes wire:poll,
            // routed through the state machine so BoundaryArchTest's
            // noOtherInboxScanStateMutator covers this column too.
            $sm->recordBackfillProgress($this->inboxId, [
                'fetched_count' => $fetched,
                'total_estimated' => $page['totalEstimated'],
                'last_message_date' => $page['lastMessageDate'],
            ]);

            $cursor = $page['nextCursor'];
            if ($cursor === null || $cursor === '') {
                break;
            }

            // Throttle between pages so the read quota envelope
            // is not exhausted by a tight loop. Sleep::sleep
            // is fakeable via Sleep::fake() in tests.
            Sleep::sleep(2);
        }
    }

    // Laravel calls this after the worker exhausts its retry budget;
    // container DI resolves InboxScanStateMachine so the same
    // sole-mutator surface handles the terminal error transition.
    public function failed(
        Throwable $exception,
        InboxScanStateMachine $sm,
        LoggerInterface $logger,
    ): void {
        try {
            $sm->applyStatus(
                $this->inboxId,
                'error',
                substr($exception->getMessage(), 0, 500),
            );
        } catch (Throwable $stateWriteFailure) {
            // An invalid transition here is acceptable, but a genuine
            // write failure (e.g. SQLITE_BUSY) leaves the inbox
            // stranded with no UI signal — log a warning so it stays
            // discoverable.
            $logger->warning(
                'BackfillInboxJob::failed could not apply the terminal error state.',
                [
                    'inbox_id' => $this->inboxId,
                    'original_failure' => $exception->getMessage(),
                    'state_write_failure' => $stateWriteFailure->getMessage(),
                ],
            );
        }
    }

    private function clearProgressAndIdle(
        Connection $connection,
        Clock $clock,
        InboxScanStateMachine $sm,
    ): void {
        // Clears the progress payload and flips status back to idle,
        // both through the state machine so its lifecycle write
        // boundary covers backfill_progress alongside status/cursor.
        unset($connection, $clock);
        $sm->recordBackfillProgress($this->inboxId, null);
        $sm->applyStatus($this->inboxId, 'idle');
    }
}
