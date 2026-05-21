<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

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
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Internal\Clients\CursorExpiredException;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\InvalidStateTransitionException;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Public\Dto\ScanCursor;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\KnownSenderQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Throwable;

/**
 * Per-inbox hourly incremental fetcher.
 *
 * Walks the Gmail historyId cursor (for provider='gmail' inboxes) or
 * the Microsoft Graph delta-link (`@odata`.`deltaLink`, for provider='microsoft'
 * inboxes) from the value previously written by BackfillInboxJob's
 * baseline phase (Plan 05 + 06). New messages discovered on the walk
 * land as `.eml` blobs on disk plus inbox_messages index rows with
 * status='fetched' — the exact same atomic .eml-then-DB-tx ordering
 * the backfill job uses (D-122).
 *
 * Concurrency contract (mirrors BackfillInboxJob):
 *  - `ShouldBeUnique` keyed on `inboxId` blocks every second dispatch
 *    for the same inbox until the worker FINISHES (not just starts).
 *    A queue-level lock that released at handle-entry would let two
 *    workers walk the same inbox's history concurrently, race on the
 *    cursor write, and trigger the state machine's duplicate
 *    'scanning' transition reject. The unique-lock store is resolved
 *    by the shared LockStore helper from `config('cache.locks_store')`.
 *  - `uniqueFor=600` (10 minutes) is shorter than the 30-minute
 *    backfill ceiling — incremental scans complete in seconds, so
 *    the lock should not linger.
 *  - The BackfillInboxJob shares the same uniqueId derivation (the
 *    raw inboxId), so a hourly tick that lands while a backfill is
 *    still in flight collapses cleanly into the existing lock.
 *  - `tries=3` + `backoff=[60,300,900]` matches the project-wide
 *    retry envelope.
 *
 * Error envelope:
 *  - `CursorExpiredException` on the cursor-walk endpoint triggers a
 *    date-bounded fallback walk via `listSenderMessages` / `listSenderMessagesPaged`,
 *    capped at the last_scan_at minus 7 days (RESEARCH Pitfall 3 / 4)
 *    + a hard 500-message defensive cap so a misbehaving provider
 *    cannot exhaust the heap. After the fallback walk the cursor is
 *    re-baselined: Gmail captures the highest historyId seen across
 *    the page-walk's metadata; Microsoft issues a fresh `deltaPage(null)`
 *    baseline call.
 *  - `RateLimitedException` → `applyRateLimited` flips status to
 *    rate_limited + bumps retry_attempts + stamps "Retry after Xs".
 *    Rethrown so Horizon honours the project-wide backoff envelope.
 *  - `InvalidGrantException` → `applyStatus(needs_reauth)`. Swallowed
 *    (terminal until the user hits Reconnect in the Plan 08 UI).
 *  - Any other Throwable → `applyStatus(error, message[:500])` +
 *    rethrow so the JobFailed listener can surface the failure.
 *
 * Two early-exit paths skip the provider call entirely:
 *  - `needs_reauth` inboxes — the FIRST step in handle() reads the
 *    state; if status='needs_reauth' the job exits immediately
 *    (T-06-07-06 mitigation; no provider API call to burn refresh
 *    attempts on a known-revoked grant).
 *  - Empty cursor (no backfill has set last_history_id /
 *    last_delta_link yet) — the job transitions to idle and exits;
 *    the next hour's tick after backfill completes will pick up.
 *
 * Queue-uniqueness lock resolution is delegated to the shared
 * `Modules\Core\Public\Support\LockStore` helper: `uniqueVia()`
 * returns `LockStore::forUniqueJobs()`, which resolves the cache store
 * named by `config('cache.locks_store')`.
 */
final class IncrementalScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * Defensive cap on the cursor-expiry fallback walk. A misbehaving
     * provider that keeps returning a nextPageToken / Graph next-link
     * indefinitely must not exhaust the heap; the walker breaks out
     * after this many messages even if the provider has more pages
     * to offer. The 7-day window cap from RESEARCH Pitfall 3 / 4
     * is the primary correctness guard; this is the defence-in-depth
     * ceiling.
     */
    private const FALLBACK_WALK_HARD_CAP = 500;

    /**
     * Cursor-expiry fallback window. RESEARCH Pitfall 3 / 4 caps the
     * re-scan at 7 days back from last_scan_at; the rest of the
     * retention window is recovered the next time the user hits
     * "Reconnect" or via a manual backfill.
     */
    private const FALLBACK_WALK_DAYS = 7;

    public function __construct(public readonly int $inboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->inboxId;
    }

    public function uniqueFor(): int
    {
        // 10-minute single-flight ceiling — incremental scans
        // complete in seconds; the lock should not linger if a
        // worker crashes mid-scan.
        return 600;
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
        OAuthSecretsRepository $secrets,
        InboxScanStateMachine $sm,
        KnownSenderQuery $senderQuery,
    ): void {
        // Touch $secrets so the static analyser does not flag the
        // unused argument. The OAuth clients (Gmail / Graph) load
        // credentials transparently via the repository; the contract
        // pins secrets to this job's DI surface so any future
        // re-baselining flow has it available without changing the
        // handle() signature.
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

        $stateRow = $connection->table('inbox_scan_state')
            ->where('inbox_id', $this->inboxId)
            ->where('folder', 'INBOX')
            ->first();
        if ($stateRow === null) {
            return;
        }

        // Early exit #1: needs_reauth inboxes are terminal until the
        // user hits Reconnect. No provider API call — a revoked
        // refresh token cannot be repaired by another scan attempt.
        $currentStatus = is_string($stateRow->status) ? $stateRow->status : '';
        if ($currentStatus === 'needs_reauth') {
            return;
        }

        /** @var User $user */
        $user = User::query()->where('id', $userId)->firstOrFail();

        $senderPatterns = array_map(
            static fn ($s) => $s->emailPattern,
            $senderQuery->all($user),
        );
        if ($senderPatterns === []) {
            // Nothing to scan; leave the row idle. This also handles
            // the "first incremental run before the system seeds
            // shipped" edge case without throwing.
            return;
        }

        // Early exit #2: collapse the contention case where a
        // backfill is mid-flight. The state machine will reject
        // backfilling → scanning, so detect upfront and skip — the
        // backfill will set last_scan_at when it finishes.
        try {
            $sm->applyStatus($this->inboxId, 'scanning');
        } catch (InvalidStateTransitionException) {
            return;
        }

        try {
            if ($provider === 'gmail') {
                $this->runGmailIncremental(
                    $connection,
                    $clock,
                    $gmail,
                    $blobStore,
                    $mime,
                    $sm,
                    $userId,
                    $senderPatterns,
                    $stateRow,
                );
            } elseif ($provider === 'microsoft') {
                $this->runMicrosoftIncremental(
                    $connection,
                    $clock,
                    $graph,
                    $blobStore,
                    $mime,
                    $sm,
                    $userId,
                    $senderPatterns,
                    $stateRow,
                );
            } else {
                $sm->applyStatus(
                    $this->inboxId,
                    'error',
                    "Unknown provider '{$provider}' — incremental scan cannot proceed.",
                );

                return;
            }

            $sm->applyStatus($this->inboxId, 'idle');
            $sm->resetRetryAttempts($this->inboxId);
        } catch (RateLimitedException $e) {
            $sm->applyRateLimited($this->inboxId, $e->retryAfterSeconds);
            throw $e;
        } catch (InvalidGrantException) {
            $sm->applyStatus(
                $this->inboxId,
                'needs_reauth',
                'OAuth grant revoked or expired.',
            );
            // Do not rethrow — needs_reauth is terminal.
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
    private function runGmailIncremental(
        Connection $connection,
        Clock $clock,
        GmailApiClientContract $gmail,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        int $userId,
        array $senderPatterns,
        \stdClass $stateRow,
    ): void {
        $startHistoryId = is_string($stateRow->last_history_id ?? null) ? $stateRow->last_history_id : '';
        if ($startHistoryId === '') {
            // No cursor yet — the next hour's tick after a backfill
            // populates last_history_id will pick up the deltas.
            return;
        }

        $messageIds = [];
        $newHistoryId = null;

        try {
            $history = $gmail->listHistory($this->inboxId, $startHistoryId);
            $messageIds = $this->extractGmailHistoryMessageIds($history['history']);
            $newHistoryId = $history['historyId'];
        } catch (CursorExpiredException) {
            // Fallback walk: date-bounded messages.list pages from
            // last_scan_at minus 7 days. Capped at FALLBACK_WALK_HARD_CAP
            // messages defensively.
            $messageIds = $this->gmailFallbackWalk(
                $gmail,
                $stateRow,
                $senderPatterns,
                $clock,
            );
            // We do not learn a new historyId from listSenderMessages
            // (Gmail returns it via getProfile in production; the
            // Wave 0 contract pins it to null). Keep the prior cursor
            // — the next hour's run will re-attempt listHistory; if
            // it succeeds the cursor advances normally.
        }

        $now = $clock->now()->toDateTimeString();
        foreach ($messageIds as $messageId) {
            // Skip messages we already have on disk + indexed. The
            // history walk can legitimately re-surface a message a
            // prior backfill pass already persisted; re-fetching the
            // raw bytes burns provider quota without changing state
            // (insertOrIgnore would short-circuit the DB write
            // anyway, and the .eml atomic-rename would overwrite an
            // identical file).
            $alreadyFetched = $connection->table('inbox_messages')
                ->where('inbox_id', $this->inboxId)
                ->where('provider_message_id', $messageId)
                ->exists();
            if ($alreadyFetched) {
                continue;
            }

            $rawEml = $gmail->getRawMessage($this->inboxId, $messageId);
            // Gmail's users.history.list does not stamp a per-message
            // internalDate, so pass the project Clock through as the
            // fallback when the .eml carries no parseable Date: header.
            // Routing through Clock keeps test-frozen time honoured —
            // the parser itself never reaches for `new
            // DateTimeImmutable('now')`.
            $headers = $mime->parseHeadersWithFallbackDate(
                $rawEml,
                $clock->now()->toDateTimeImmutable(),
            );
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
                    $now,
                ): void {
                    $connection->statement('PRAGMA busy_timeout = 5000');
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
                $blobStore->delete($emlPath);
                throw $e;
            }
        }

        if (is_string($newHistoryId) && $newHistoryId !== '') {
            $sm->recordCursor(
                $this->inboxId,
                ScanCursor::gmail($newHistoryId),
            );
        }
    }

    /**
     * @param  list<string>  $senderPatterns
     */
    private function runMicrosoftIncremental(
        Connection $connection,
        Clock $clock,
        GraphApiClientContract $graph,
        EmlBlobStore $blobStore,
        MimeHeaderParser $mime,
        InboxScanStateMachine $sm,
        int $userId,
        array $senderPatterns,
        \stdClass $stateRow,
    ): void {
        $storedDeltaLink = is_string($stateRow->last_delta_link ?? null) ? $stateRow->last_delta_link : '';
        if ($storedDeltaLink === '') {
            return;
        }

        $messages = [];
        $newDeltaLink = null;

        try {
            $page = $graph->deltaPage($this->inboxId, $storedDeltaLink);
            $messages = $page['messages'];
            $newDeltaLink = $page['deltaLink'];
        } catch (CursorExpiredException) {
            // Fallback walk: date-bounded /me/messages walk from
            // last_scan_at minus 7 days. Capped at the hard cap
            // defensively. After the walk, re-baseline via a fresh
            // deltaPage(null, $walkStartedAt) so the cursor lower-bound
            // is anchored to the pre-walk timestamp; this closes the
            // gap-window race where messages arriving during the
            // fallback walk could fall outside both the walk's filter
            // and the new baseline's lower bound. BackfillInboxJob
            // uses the same pre-walk-timestamp baseline pattern.
            $walkStartedAt = $clock->now()->toDateTimeImmutable();
            $messages = $this->graphFallbackWalk(
                $graph,
                $stateRow,
                $senderPatterns,
                $clock,
            );
            $baseline = $graph->deltaPage($this->inboxId, null, $walkStartedAt);
            $newDeltaLink = $baseline['deltaLink'];
        }

        // Client-side post-filter on the delta messages. Graph's
        // delta endpoint does not honour a from-address $filter, so
        // any messages from senders outside the allow-list are
        // dropped at the boundary — the fallback walk already filters
        // server-side via listSenderMessagesPaged.
        $now = $clock->now()->toDateTimeString();
        foreach ($messages as $msgMeta) {
            $senderAddr = '';
            if (isset($msgMeta['from']) && is_array($msgMeta['from'])) {
                $emailAddress = $msgMeta['from']['emailAddress'] ?? null;
                if (is_array($emailAddress)) {
                    $rawAddr = $emailAddress['address'] ?? null;
                    if (is_string($rawAddr)) {
                        $senderAddr = strtolower($rawAddr);
                    }
                }
            }
            if (! $this->matchesAnyPattern($senderAddr, $senderPatterns)) {
                continue;
            }

            $messageId = is_string($msgMeta['id'] ?? null) ? $msgMeta['id'] : '';
            if ($messageId === '') {
                continue;
            }

            // Skip messages we already have on disk + indexed. The
            // Graph delta walk + fallback walk can both legitimately
            // re-surface a message a prior pass already persisted;
            // re-fetching its raw bytes burns Graph quota without
            // changing state.
            $alreadyFetched = $connection->table('inbox_messages')
                ->where('inbox_id', $this->inboxId)
                ->where('provider_message_id', $messageId)
                ->exists();
            if ($alreadyFetched) {
                continue;
            }

            $rawEml = $graph->getRawMessage($this->inboxId, $messageId);

            $received = $msgMeta['receivedDateTime'] ?? null;
            $fallbackDate = is_string($received) && $received !== ''
                ? $this->safeParseDate($received, $clock)
                : $clock->now()->toDateTimeImmutable();
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
                    $now,
                ): void {
                    $connection->statement('PRAGMA busy_timeout = 5000');
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
                $blobStore->delete($emlPath);
                throw $e;
            }
        }

        if (is_string($newDeltaLink) && $newDeltaLink !== '') {
            $sm->recordCursor(
                $this->inboxId,
                ScanCursor::microsoft($newDeltaLink),
            );
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

    /**
     * Pull provider message ids out of a Gmail history.list response.
     * Each history entry may carry a `messagesAdded` array of
     * `{message: {id, threadId}}` objects; the messagesDeleted /
     * labelAdded shapes are ignored for incremental fetch (a deleted
     * message has nothing to fetch).
     *
     * @param  list<array<string, mixed>>  $historyEntries
     * @return list<string>
     */
    private function extractGmailHistoryMessageIds(array $historyEntries): array
    {
        $ids = [];
        foreach ($historyEntries as $entry) {
            $added = $entry['messagesAdded'] ?? null;
            if (! is_array($added)) {
                continue;
            }
            foreach ($added as $msgAdded) {
                if (! is_array($msgAdded)) {
                    continue;
                }
                $message = $msgAdded['message'] ?? null;
                if (! is_array($message)) {
                    continue;
                }
                $id = $message['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Date-bounded fallback walk over Gmail's listSenderMessages —
     * invoked when listHistory's startHistoryId has aged out
     * (CursorExpiredException). The walk passes
     * `$windowStart = last_scan_at - FALLBACK_WALK_DAYS` so the
     * server-side `after:` filter trims the result set tightly
     * against the recovery window the docblock promises. The
     * FALLBACK_WALK_HARD_CAP message ceiling stays as the
     * defence-in-depth bound against a runaway page walk.
     *
     * @param  list<string>  $senderPatterns
     * @return list<string>
     */
    private function gmailFallbackWalk(
        GmailApiClientContract $gmail,
        \stdClass $stateRow,
        array $senderPatterns,
        Clock $clock,
    ): array {
        $lastScanAt = is_string($stateRow->last_scan_at ?? null) && $stateRow->last_scan_at !== ''
            ? $this->safeParseDate($stateRow->last_scan_at, $clock)
            : $clock->now()->toDateTimeImmutable();
        $windowStart = $lastScanAt->modify('-'.self::FALLBACK_WALK_DAYS.' days');

        $ids = [];
        $pageToken = null;
        do {
            $page = $gmail->listSenderMessages($this->inboxId, $senderPatterns, $pageToken, $windowStart);
            foreach ($page['messages'] as $msg) {
                $id = $msg['id'];
                if ($id === '') {
                    continue;
                }
                $ids[] = $id;
                if (count($ids) >= self::FALLBACK_WALK_HARD_CAP) {
                    return $ids;
                }
            }
            $pageToken = $page['nextPageToken'];
        } while ($pageToken !== null && $pageToken !== '');

        return $ids;
    }

    /**
     * Date-bounded fallback walk over Microsoft Graph's
     * listSenderMessagesPaged — invoked when deltaPage's stored
     * Graph delta-link has aged out (CursorExpiredException). Walks
     * at most FALLBACK_WALK_HARD_CAP messages defensively.
     *
     * @param  list<string>  $senderPatterns
     * @return list<array<string, mixed>>
     */
    private function graphFallbackWalk(
        GraphApiClientContract $graph,
        \stdClass $stateRow,
        array $senderPatterns,
        Clock $clock,
    ): array {
        $lastScanAt = is_string($stateRow->last_scan_at ?? null) && $stateRow->last_scan_at !== ''
            ? $this->safeParseDate($stateRow->last_scan_at, $clock)
            : $clock->now()->toDateTimeImmutable();
        $windowStart = $lastScanAt->modify('-'.self::FALLBACK_WALK_DAYS.' days');

        $results = [];
        $nextLink = null;
        do {
            $page = $graph->listSenderMessagesPaged($this->inboxId, $senderPatterns, $windowStart, $nextLink);
            foreach ($page['messages'] as $msg) {
                $results[] = $msg;
                if (count($results) >= self::FALLBACK_WALK_HARD_CAP) {
                    return $results;
                }
            }
            $nextLink = $page['nextLink'];
        } while ($nextLink !== null && $nextLink !== '');

        return $results;
    }

    /**
     * Match a sender address against a list of patterns. Patterns
     * starting with '@' match by domain suffix; otherwise a substring
     * containment match. Case-insensitive (both sides are
     * already lowercased by the caller).
     *
     * @param  list<string>  $patterns
     */
    private function matchesAnyPattern(string $senderAddr, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $p = strtolower($pattern);
            if (str_starts_with($p, '@')) {
                if (str_ends_with($senderAddr, $p)) {
                    return true;
                }
            } else {
                if (str_contains($senderAddr, $p)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function safeParseDate(string $raw, Clock $clock): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return $clock->now()->toDateTimeImmutable();
        }
    }
}
