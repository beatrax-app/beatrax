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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\EmlBlobStore;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Public\Dto\ScanCursor;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Throwable;

/**
 * The queued chunked fetcher for one connected inbox's backfill.
 *
 * Concurrency contract:
 *  - `ShouldBeUnique` keyed on `inboxId` blocks every second dispatch
 *    for the same inbox until the worker FINISHES (not just starts).
 *    A multi-page backfill takes minutes; a queue-level lock that
 *    released at handle-entry would let two workers walk the same
 *    inbox's first page in parallel, racing on the backfill_progress
 *    payload and the per-page cursor, and would also trigger the
 *    state machine's reject for the duplicate 'idle → backfilling'
 *    re-entry. ShouldBeUnique keeps the lock until handle() returns
 *    (success or throw) so concurrent dispatches collapse cleanly to
 *    one. The unique-lock store is Redis (the project's only permitted
 *    facade exception — Laravel calls `uniqueVia()` at push-time
 *    before constructor DI resolves).
 *  - `tries = 3` + `backoff = [60, 300, 900]` matches the
 *    project-wide retry envelope so the per-inbox state machine's
 *    rate-limited / error transitions ride the same back-off curve.
 *
 * Provider dispatch:
 *  - `provider === 'gmail'` walks Gmail's `users.messages.list` +
 *    `users.messages.get?format=raw`; the historyId baseline cursor
 *    is captured from the per-page metadata.
 *  - `provider === 'microsoft'` walks Graph's `/me/messages` with an
 *    OData `$filter` over the sender allow-list + a receivedDateTime
 *    lower bound; after the walk completes, a SINGLE
 *    `/me/mailFolders/inbox/messages/delta` baseline call captures
 *    the `@odata.deltaLink` cursor (two-phase pattern — RESEARCH
 *    Pattern 4). Provider-stamped receivedDateTime is the canonical
 *    internal_date so a missing in-body Date: header never silently
 *    lands on the wall clock.
 *
 * Per-message walk shape (shared between both providers via the
 * private `walkAndPersist` helper):
 *  - Fetch raw bytes from the provider, parse the four RFC 822
 *    header values needed for the index row, write the .eml to disk
 *    first, then open a small DB transaction that inserts the
 *    inbox_messages row with insertOrIgnore (the unique constraint
 *    on `(inbox_id, provider_message_id)` makes a retry of the same
 *    id a no-op).
 *  - On a tx failure the catch block unlinks the .eml so there is
 *    never an orphan blob on disk without a matching index row.
 *  - Per-page: bump `inboxes.backfill_progress` so the /inboxes
 *    progress strip's `wire:poll.2s` has fresh counters to render.
 *  - Between pages: sleep two seconds via Sleep::sleep so the
 *    provider quota envelope is not exhausted by a tight loop. The
 *    sleep is fakeable in tests via Sleep::fake().
 *
 * Cooperative-shutdown caveat (acknowledged trade-off): `Sleep::sleep`
 * blocks the worker for the full two seconds without checking whether
 * the queue worker has been signalled to restart (e.g. via
 * `php artisan queue:restart`, which workers honour between jobs but
 * not mid-sleep). A year-long backfill therefore extends the lag a
 * `queue:restart` takes to complete by up to a few minutes per inbox.
 * For the single-user v1 deployment this is acceptable; the multi-
 * user readiness goal makes it worth revisiting later — the cleaner
 * shape is `release(60)` between pages so the worker is freed for
 * other tenants instead of holding the slot across the throttle
 * wait, but that requires reshaping the job's state-carry-across-
 * dispatch contract (the per-page accumulators currently live on the
 * stack frame of handle()).
 *
 * Window clamp (defensive): the slider clamps client-side, but a
 * crafted POST could carry windowMonths=999. The handler re-clamps
 * to [1, 12] before any further work — the constructor is too
 * early to read the property in a deserialised job, and constructor
 * argument validation would invalidate the inbox-id-keyed unique
 * lock if it threw mid-push.
 *
 * Error envelope (both branches):
 *  - RateLimitedException → transition to `rate_limited`, rethrow
 *    so the queue worker schedules the next attempt.
 *  - InvalidGrantException → transition to `needs_reauth` and
 *    swallow the throw (the user must reconnect; another retry
 *    cannot make progress).
 *  - Any other throwable → transition to `error` (with the first
 *    500 chars of the exception message) and rethrow so the
 *    JobFailed listener can surface the failure.
 *
 * Single permitted facade exception: the `Cache::driver('redis')`
 * call inside `uniqueVia()`. The Laravel queue infrastructure
 * invokes `uniqueVia()` at push-time before constructor DI
 * completes; a constructor-injected `Repository` is not an option.
 * The `tests/Contracts/BoundaryArchTest.php` allow-list grants
 * this file FQN the "no Laravel facades in module code" exemption.
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
        // 30-minute single-flight ceiling — long enough for a
        // multi-page backfill of a year of receipts to finish,
        // short enough that a worker crash unblocks the next
        // dispatch promptly.
        return 1800;
    }

    public function uniqueVia(): Repository
    {
        // The Cache facade is the single permitted facade use in
        // module code (BoundaryArchTest carve-out). Laravel calls
        // uniqueVia() before constructor DI completes — there is
        // no path to inject a Repository at this point.
        return Cache::driver('redis');
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        GmailApiClientContract $gmail,
        GraphApiClientContract $graph,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        OAuthSecretsRepository $secrets,
        InboxScanStateMachine $sm,
        KnownSenderQuery $senderQuery,
    ): void {
        // Touch $secrets so the static analyser does not flag the
        // unused argument. The OAuth client (Gmail / Graph) loads
        // credentials transparently; the contract still pins
        // secrets to this job's DI surface because future
        // re-baselining flows in later plans use it directly.
        unset($secrets);

        $connection = $db->connection();

        $inboxRow = $connection->table('inboxes')->where('id', $this->inboxId)->first();
        if ($inboxRow === null) {
            // Inbox was deleted between dispatch and worker pickup —
            // silently exit so the queue does not retry forever.
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

        // Read known senders (system seeds + user additions). Both
        // providers use the same allow-list as a server-side filter.
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

        // Unknown provider — surface the misconfiguration to the UI
        // without retrying forever. The inboxes row's CHECK trigger
        // pair (Plan 02) keeps gmail|microsoft the only legal values
        // in production, so this arm is reached only if the data
        // bypassed the migration triggers.
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
    ): void {
        $sm->applyStatus($this->inboxId, 'backfilling');

        // Mutable accumulators captured by the page closure. The
        // running estimate is the max() of the per-page hints; the
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
                fetchNextPage: function (?string $cursor) use ($gmail, $senderPatterns, $accum): array {
                    $page = $gmail->listSenderMessages($this->inboxId, $senderPatterns, $cursor);
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

            // Set the baseline cursor only via the state machine's
            // sole-mutator surface so the BoundaryArchTest stays
            // green.
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

        $windowStart = $clock->now()->modify("-{$windowMonths} months");

        // Mutable accumulators captured by the page closure. The
        // running estimate is the max count of messages seen across
        // pages; lastMessageDate is the most recent receivedDateTime
        // (used by the progress strip + the Summary's "as of"
        // timestamp).
        $accum = new class
        {
            public int $estimated = 0;

            public ?string $lastMessageDate = null;
        };

        try {
            // 1. Backfill phase — non-delta walk of /me/messages with
            //    the (from in [...]) and receivedDateTime ge {start}
            //    OData filter. @odata.nextLink terminates the loop.
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
                    // Graph does not return a result-size estimate.
                    // The progress strip counts the fetched messages so
                    // far + treats it as the per-page running estimate
                    // (matches the Plan 05 progress shape verbatim for
                    // the Gmail branch, just without the upstream
                    // estimate hint).
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

            // 2. Baseline phase — a SINGLE delta call to establish the
            //    cursor. This must sit AFTER the walk loop ends and
            //    BEFORE the inbox is marked idle. The deltaLink write
            //    goes through the state machine's recordCursor surface
            //    so the noOtherInboxScanStateMutator boundary holds.
            $baseline = $graph->deltaPage($this->inboxId, null);
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

    /**
     * Provider-agnostic walker: iterate pages via the closure-based
     * fetcher, write each message's .eml then DB row (atomic + orphan-
     * cleaned), update the progress strip per page, and sleep two
     * seconds between pages so the provider quota envelope is not
     * exhausted by a tight loop.
     *
     * Closures isolate the per-provider differences:
     *  - `fetchNextPage($cursor)` returns
     *    `{messages, nextCursor, totalEstimated, lastMessageDate}`.
     *  - `extractMessageId($msgMeta)` returns the provider message id
     *    used as the .eml filename and as the unique row key.
     *  - `fetchRawEml($messageId)` returns the raw RFC 822 byte
     *    stream (Gmail decodes base64url for us; Graph returns the
     *    bytes verbatim).
     *  - `extractInternalDate($msgMeta)` returns the provider-stamped
     *    internal_date OR null. Null means "use the .eml's Date:
     *    header" — Gmail's `users.messages.list` does not return
     *    per-message receivedDateTime, so the Gmail branch passes
     *    null and falls back to in-body Date: header parsing.
     *
     * @param  Closure(?string): array{messages: list<array<string, mixed>>, nextCursor: ?string, totalEstimated: int, lastMessageDate: ?string}  $fetchNextPage
     * @param  Closure(array<string, mixed>): string  $extractMessageId
     * @param  Closure(string): string  $fetchRawEml
     * @param  Closure(array<string, mixed>): ?DateTimeImmutable  $extractInternalDate
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

                // Skip messages we already have on disk + indexed.
                // The "extend window" flow re-runs a backfill that
                // overlaps the prior window; the cursor-expiry
                // fallback walk can also re-walk recent messages. In
                // both cases, refetching the raw bytes burns provider
                // quota without changing state.
                $alreadyFetched = $connection->table('inbox_messages')
                    ->where('inbox_id', $this->inboxId)
                    ->where('provider_message_id', $messageId)
                    ->exists();
                if ($alreadyFetched) {
                    continue;
                }

                $rawEml = $fetchRawEml($messageId);

                // Provider-supplied internal date (Microsoft) vs. in-
                // body Date: header (Gmail). Either way the parser
                // returns the four index fields together.
                $internalDate = $extractInternalDate($msgMeta);
                $headers = $internalDate === null
                    ? $mime->parseHeaders($rawEml)
                    : $mime->parseHeadersWithFallbackDate($rawEml, $internalDate);

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

            // Update the live progress payload so the /inboxes
            // wire:poll has fresh counters. Routed through the state
            // machine so the per-inbox lifecycle write boundary
            // (BoundaryArchTest noOtherInboxScanStateMutator) covers
            // backfill_progress alongside status / cursor columns.
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

    /**
     * Laravel calls this method on the resolved job instance after the
     * worker exhausts its retry budget. Container DI resolves the
     * InboxScanStateMachine argument so the same sole-mutator surface
     * the in-flight pipeline uses also handles the terminal error
     * transition.
     *
     * Replaces the previous JobFailed listener + regex extraction over
     * the serialised job payload (which was fragile against
     * serialiser-format changes and a future job class whose
     * property name shared a prefix with 'inboxId').
     *
     * Invalid transitions (e.g. an already-needs_reauth inbox failing
     * again) are swallowed so the failed-hook surface never escalates
     * a recovery scenario into a hard queue-worker error.
     */
    public function failed(Throwable $exception, InboxScanStateMachine $sm): void
    {
        try {
            $sm->applyStatus(
                $this->inboxId,
                'error',
                substr($exception->getMessage(), 0, 500),
            );
        } catch (Throwable) {
            // Swallow — invalid transitions are acceptable on the
            // failed-hook surface.
        }
    }

    private function clearProgressAndIdle(
        Connection $connection,
        Clock $clock,
        InboxScanStateMachine $sm,
    ): void {
        // Clear the progress payload so the /inboxes strip
        // hides itself, and flip the per-inbox status back to
        // idle. Both writes go through the state machine — the
        // per-inbox lifecycle write boundary covers backfill_progress
        // alongside status / cursor columns.
        unset($connection, $clock);
        $sm->recordBackfillProgress($this->inboxId, null);
        $sm->applyStatus($this->inboxId, 'idle');
    }
}
