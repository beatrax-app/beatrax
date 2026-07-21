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
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
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

    // Defensive cap on the cursor-expiry fallback walk so a
    // misbehaving provider returning pages indefinitely cannot
    // exhaust the heap; the 7-day window is the primary guard, this
    // is the defence-in-depth ceiling.
    private const FALLBACK_WALK_HARD_CAP = 500;

    // Cursor-expiry fallback window: caps the re-scan at 7 days back
    // from last_scan_at; the rest of the retention window is
    // recovered on the next Reconnect or manual backfill.
    private const FALLBACK_WALK_DAYS = 7;

    public function __construct(public readonly int $inboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->inboxId;
    }

    public function uniqueFor(): int
    {
        // 10-minute single-flight ceiling: incremental scans complete
        // in seconds, so the lock shouldn't linger past a crash.
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

        $stateRow = $connection->table('inbox_scan_state')
            ->where('inbox_id', $this->inboxId)
            ->where('folder', 'INBOX')
            ->first();
        if ($stateRow === null) {
            return;
        }

        // needs_reauth inboxes are terminal until the user hits
        // Reconnect: no provider API call, since a revoked refresh
        // token cannot be repaired by another scan attempt.
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
            // Nothing to scan; leave the row idle (also covers the
            // first-run-before-seeds-shipped edge case without throwing).
            return;
        }

        // Collapses the contention case where a backfill is
        // mid-flight: the state machine rejects backfilling ->
        // scanning, so detect it upfront and skip rather than error.
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
            $messageIds = $this->gmailFallbackWalk(
                $gmail,
                $stateRow,
                $senderPatterns,
                $clock,
            );
            // listSenderMessages never returns a historyId (Gmail
            // surfaces it via getProfile in production), so the prior
            // cursor is kept — the next run re-attempts listHistory.
        }

        $now = $clock->now()->toDateTimeString();
        foreach ($messageIds as $messageId) {
            // Skips a message already fetched+indexed: the history
            // walk can legitimately re-surface one a prior backfill
            // already persisted, and refetching burns quota for nothing.
            $alreadyFetched = $connection->table('inbox_messages')
                ->where('inbox_id', $this->inboxId)
                ->where('provider_message_id', $messageId)
                ->exists();
            if ($alreadyFetched) {
                continue;
            }

            $rawEml = $gmail->getRawMessage($this->inboxId, $messageId);
            // Gmail's users.history.list stamps no per-message
            // internalDate, so the project Clock is the fallback when
            // the .eml carries no parseable Date: header (keeping
            // test-frozen time honoured rather than a raw 'now').
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
            // Fallback walk anchored to the pre-walk timestamp, then
            // re-baselined via deltaPage(null, $walkStartedAt) — the
            // same pattern BackfillInboxJob uses to close the
            // gap-window race (see architecture.md).
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

        // Client-side post-filter: Graph's delta endpoint doesn't
        // honour a from-address $filter, so senders outside the
        // allow-list are dropped here (the fallback walk already
        // filters server-side via listSenderMessagesPaged).
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

            // Skips a message already fetched+indexed: both the delta
            // walk and the fallback walk can legitimately re-surface
            // one a prior pass already persisted.
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

    // Laravel calls this after the worker exhausts its retry budget;
    // container DI resolves InboxScanStateMachine so the same
    // sole-mutator surface handles the terminal error transition.
    public function failed(Throwable $exception, InboxScanStateMachine $sm): void
    {
        try {
            $sm->applyStatus(
                $this->inboxId,
                'error',
                substr($exception->getMessage(), 0, 500),
            );
        } catch (Throwable) {
            // Invalid transitions are acceptable on this hook — they
            // must not escalate into a hard queue-worker error.
        }
    }

    // Pulls provider message ids out of a Gmail history.list response;
    // each entry may carry a messagesAdded array of
    // {message: {id, threadId}} objects, and the messagesDeleted/
    // labelAdded shapes are ignored (a deleted message has nothing to fetch).
    /**
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

    // Date-bounded fallback walk over Gmail's listSenderMessages,
    // invoked when listHistory's startHistoryId has aged out; passes
    // $windowStart = last_scan_at - FALLBACK_WALK_DAYS so the
    // server-side after: filter trims the result set to the recovery window.
    /**
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

    // Date-bounded fallback walk over Microsoft Graph's
    // listSenderMessagesPaged, invoked when deltaPage's stored
    // delta-link has aged out; walks at most FALLBACK_WALK_HARD_CAP
    // messages defensively.
    /**
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

    // Matches a sender address against a pattern list: patterns
    // starting with '@' match by domain suffix, otherwise a substring
    // containment match (case-insensitive, both sides lowercased).
    /**
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
