<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Closure;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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

        // Defensive window clamp — the slider clamps client-side
        // but a crafted POST may carry an out-of-range value.
        $window = max(1, min(12, $this->windowMonths));

        $context = new InboxScanContext($this->inboxId, $clock, $sm, $connection, $blobStore, $mime, $userId);

        // The inboxes CHECK trigger pair keeps gmail|microsoft the only
        // legal production values, so the default arm is reached only if
        // data bypassed the migration; it surfaces the misconfiguration
        // without retrying forever rather than walking an empty provider.
        match ($provider) {
            'gmail' => $this->runGmailBackfill($context, $gmail, $senderPatterns, $window),
            'microsoft' => $this->runMicrosoftBackfill($context, $graph, $senderPatterns, $window),
            default => $sm->applyStatus(
                $this->inboxId,
                'error',
                "Unknown provider '{$provider}' — backfill cannot proceed.",
            ),
        };
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runGmailBackfill(
        InboxScanContext $context,
        GmailApiClientContract $gmail,
        array $senderPatterns,
        int $windowMonths,
    ): void {
        $context->sm->applyStatus($this->inboxId, 'backfilling');

        // Honours the user-selected window: the Gmail after: operator
        // bounds the q= search to this date floor so the walk stops
        // at the slider value instead of racing the full-inbox quota.
        $windowStart = $context->clock->now()->modify("-{$windowMonths} months")->toDateTimeImmutable();

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
                $context,
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
                extractMessageId: self::extractProviderMessageId(...),
                fetchRawEml: fn (string $messageId): string => $gmail->getRawMessage($this->inboxId, $messageId),
                extractInternalDate: static fn (array $msgMeta): ?DateTimeImmutable => null,
            );

            // Sets the baseline cursor only via the state machine's
            // sole-mutator surface so BoundaryArchTest stays green.
            if ($accum->highestHistoryId !== null) {
                $context->sm->recordCursor(
                    $this->inboxId,
                    ScanCursor::gmail($accum->highestHistoryId),
                );
            }

            $this->clearProgressAndIdle($context);
        } catch (Throwable $e) {
            $this->transitionOnScanError($context, $e);
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runMicrosoftBackfill(
        InboxScanContext $context,
        GraphApiClientContract $graph,
        array $senderPatterns,
        int $windowMonths,
    ): void {
        $context->sm->applyStatus($this->inboxId, 'backfilling');

        // Captures the wall-clock anchor before any provider call so
        // the post-walk deltaPage(null, anchor) baseline uses the
        // pre-walk timestamp, closing the multi-hour-backfill race
        // window (see architecture.md for the full argument).
        $walkStartedAt = $context->clock->now()->toDateTimeImmutable();
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
            $this->walkAndPersist(
                $context,
                fetchNextPage: function (?string $cursor) use ($graph, $senderPatterns, $windowStart, $accum): array {
                    $page = $graph->listSenderMessagesPaged($this->inboxId, $senderPatterns, $windowStart, $cursor);
                    $accum->estimated = max($accum->estimated, count($page['messages']));
                    $accum->lastMessageDate = self::latestReceived($page['messages'], $accum->lastMessageDate);

                    return [
                        'messages' => $page['messages'],
                        'nextCursor' => $page['nextLink'],
                        'totalEstimated' => $accum->estimated,
                        'lastMessageDate' => $accum->lastMessageDate,
                    ];
                },
                extractMessageId: self::extractProviderMessageId(...),
                fetchRawEml: fn (string $messageId): string => $graph->getRawMessage($this->inboxId, $messageId),
                extractInternalDate: fn (array $msgMeta): ?DateTimeImmutable => $this->graphMessageInternalDate($msgMeta),
            );

            // Baseline phase: a single delta call after the walk ends
            // and before idle, pinned to the pre-walk anchor so
            // mid-walk messages are captured by the next incremental
            // tick rather than falling into a walk-end/baseline gap.
            $baseline = $graph->deltaPage($this->inboxId, null, $walkStartedAt);
            $deltaLink = $baseline['deltaLink'] ?? null;
            if ($deltaLink !== null && $deltaLink !== '') {
                $context->sm->recordCursor(
                    $this->inboxId,
                    ScanCursor::microsoft($deltaLink),
                );
            }

            $this->clearProgressAndIdle($context);
        } catch (Throwable $e) {
            $this->transitionOnScanError($context, $e);
        }
    }

    // Provider-agnostic walker: iterates pages via the closure-based
    // fetcher, persists each new message through the scan context,
    // updates the progress strip per page, and sleeps between pages so
    // the provider quota isn't exhausted by a tight loop.
    /**
     * @param  Closure(?string): array{messages: list<array<string, mixed>>, nextCursor: ?string, totalEstimated: int, lastMessageDate: ?string}  $fetchNextPage
     * @param  Closure(array<string, mixed>): string  $extractMessageId
     * @param  Closure(string): string  $fetchRawEml
     * @param  Closure(array<string, mixed>): ?DateTimeImmutable  $extractInternalDate
     */
    private function walkAndPersist(
        InboxScanContext $context,
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
                $fetched += $this->persistPageMessage($context, $msgMeta, $extractMessageId, $fetchRawEml, $extractInternalDate);
            }

            // Updates the live progress payload for /inboxes wire:poll,
            // routed through the state machine so BoundaryArchTest's
            // noOtherInboxScanStateMutator covers this column too.
            $context->sm->recordBackfillProgress($this->inboxId, [
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

    /**
     * @param  array<string, mixed>  $msgMeta
     * @param  Closure(array<string, mixed>): string  $extractMessageId
     * @param  Closure(string): string  $fetchRawEml
     * @param  Closure(array<string, mixed>): ?DateTimeImmutable  $extractInternalDate
     * @return int the row count persisted for this message, 0 when skipped
     */
    private function persistPageMessage(
        InboxScanContext $context,
        array $msgMeta,
        Closure $extractMessageId,
        Closure $fetchRawEml,
        Closure $extractInternalDate,
    ): int {
        $messageId = $extractMessageId($msgMeta);
        if ($messageId === '' || $context->alreadyIndexed($messageId)) {
            return 0;
        }

        $context->storeFetchedMessage($messageId, $fetchRawEml($messageId), $extractInternalDate($msgMeta));

        return 1;
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

    private function clearProgressAndIdle(InboxScanContext $context): void
    {
        // Clears the progress payload and flips status back to idle,
        // both through the state machine so its lifecycle write
        // boundary covers backfill_progress alongside status/cursor.
        $context->sm->recordBackfillProgress($this->inboxId, null);
        $context->sm->applyStatus($this->inboxId, 'idle');
    }

    // Both provider branches share one error envelope: rate-limit
    // transitions and rethrows for the backoff curve, a revoked grant
    // parks the inbox at needs_reauth without a rethrow, and any other
    // throwable records the error before rethrowing to the failed hook.
    private function transitionOnScanError(InboxScanContext $context, Throwable $e): void
    {
        match (true) {
            $e instanceof RateLimitedException => $context->sm->applyStatus(
                $this->inboxId,
                'rate_limited',
                "Retry after {$e->retryAfterSeconds}s.",
            ),
            $e instanceof InvalidGrantException => $context->sm->applyStatus(
                $this->inboxId,
                'needs_reauth',
                'OAuth grant revoked or expired.',
            ),
            default => $context->sm->applyStatus(
                $this->inboxId,
                'error',
                substr($e->getMessage(), 0, 500),
            ),
        };

        if (! $e instanceof InvalidGrantException) {
            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private static function latestReceived(array $messages, ?string $current): ?string
    {
        if ($messages === []) {
            return $current;
        }

        $lastReceived = $messages[count($messages) - 1]['receivedDateTime'] ?? null;

        return is_string($lastReceived) && $lastReceived !== '' ? $lastReceived : $current;
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     */
    private static function extractProviderMessageId(array $msgMeta): string
    {
        return is_string($msgMeta['id'] ?? null) ? $msgMeta['id'] : '';
    }

    // Provider-stamped receivedDateTime is the canonical internal date
    // for Microsoft; a null return lets the scan context fall back to
    // the project Clock when the stamp is missing or unparseable.
    /**
     * @param  array<string, mixed>  $msgMeta
     */
    private function graphMessageInternalDate(array $msgMeta): ?DateTimeImmutable
    {
        $received = $msgMeta['receivedDateTime'] ?? null;
        if (! is_string($received) || $received === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($received);
        } catch (Throwable) {
            return null;
        }
    }
}
