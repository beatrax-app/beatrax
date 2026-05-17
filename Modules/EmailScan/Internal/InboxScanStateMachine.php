<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\ScanCursor;
use RuntimeException;

/**
 * The single legal mutator of `inbox_scan_state.status`,
 * `inbox_scan_state.retry_attempts`, and the provider cursor columns
 * (`last_history_id`, `last_delta_link`). A BoundaryArchTest invariant
 * under `tests/Contracts/BoundaryArchTest.php`
 * (`noOtherInboxScanStateMutator`) blocks every other write path under
 * `Modules/EmailScan/`.
 *
 * Public surface:
 *
 *  - `applyStatus()` validates the requested transition against the
 *    ALLOWED_TRANSITIONS const map and writes the new status +
 *    optional error_message. An invalid transition raises
 *    InvalidStateTransitionException; the wrapping transaction rolls
 *    back, leaving the row untouched. `last_scan_at` advances only on
 *    success transitions ('idle' / 'backfilling' / 'scanning') —
 *    rate_limited / needs_reauth / error keep the prior scan
 *    timestamp so the UI can surface "stuck since X" honestly.
 *
 *  - `applyRateLimited()` is the convenience surface used by the
 *    Gmail + Microsoft scan jobs when the provider responds with a
 *    rate-limit signal. Transitions to 'rate_limited', increments
 *    retry_attempts, and stamps an error_message of the form
 *    "Retry after Xs". Validates the transition the same way
 *    applyStatus does.
 *
 *  - `resetRetryAttempts()` zeros the counter; called on success
 *    transitions (e.g. after applyStatus('idle')) so a long-lived
 *    inbox does not carry a stale retry count across recovery cycles.
 *
 *  - `backoffForAttempt(int $attempt)` returns the per-attempt
 *    exponential backoff seconds from the BACKOFF_SCHEDULE const
 *    ([60, 300, 900, 3600]). Clamps both ends — attempt < 0 returns
 *    the first entry, attempt >= count returns the last entry.
 *
 *  - `recordCursor()` writes whichever cursor column matches the
 *    cursor's provider — Gmail's history id or Microsoft's delta
 *    link. Passing an empty ScanCursor is a no-op so callers can
 *    funnel "we did not learn a new cursor" through the same gate as
 *    a real write. Cross-checks the cursor's provider against the
 *    inboxes row's provider; a mismatch raises InvalidArgumentException
 *    so a Gmail cursor can never land on a Microsoft inbox row.
 *
 * SQLite contention guard: every write path opens a transaction, sets
 * `PRAGMA busy_timeout = 5000`, and reads the row via lockForUpdate.
 * SQLite's `FOR UPDATE` clause is a no-op (the engine only has one
 * writer); the busy_timeout pragma is the load-bearing fence — it
 * tells SQLite to wait up to five seconds for a competing writer
 * before raising SQLITE_BUSY (RESEARCH Pitfall 6). Two concurrent
 * IncrementalScanJob runs that briefly contend on the row therefore
 * serialise rather than fail.
 */
final class InboxScanStateMachine
{
    /**
     * Per-attempt backoff schedule in seconds. RESEARCH § Runtime
     * Knobs locks this curve at [60s, 5min, 15min, 1h]. Indices past
     * the end of the array clamp to the final entry so a runaway
     * retry count cannot push the delay to infinity.
     *
     * @var list<int>
     */
    private const BACKOFF_SCHEDULE = [60, 300, 900, 3600];

    /**
     * Per-state allowed-target map. A transition not present in this
     * map raises InvalidStateTransitionException — there is no
     * "any state → any state" escape hatch.
     *
     * Notes on a few entries that look unusual at first glance:
     *
     *  - 'idle' → 'idle' is permitted as a re-entrant no-op so the
     *    backfill job's "no senders configured" early-exit path can
     *    safely re-touch the row to surface an error_message without
     *    a sentinel transition.
     *  - 'needs_reauth' is terminal except for 'idle' (the user has
     *    hit Reconnect and we are returning to normal scanning) and
     *    'needs_reauth' itself (re-entrant safe — a second
     *    invalid_grant during the brief window before a Reconnect
     *    succeeds must not throw).
     *  - 'error' permits transitions to every non-terminal state so
     *    a transient infrastructure blip can be recovered from on
     *    the next scheduler tick without manual intervention.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'idle' => ['idle', 'backfilling', 'scanning', 'needs_reauth', 'error'],
        'backfilling' => ['idle', 'rate_limited', 'needs_reauth', 'error'],
        'scanning' => ['idle', 'rate_limited', 'needs_reauth', 'error'],
        'rate_limited' => ['backfilling', 'scanning', 'idle', 'needs_reauth', 'error'],
        'needs_reauth' => ['idle', 'needs_reauth'],
        'error' => ['idle', 'backfilling', 'scanning', 'needs_reauth', 'error'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function applyStatus(
        int $inboxId,
        string $newStatus,
        ?string $errorMessage = null,
    ): void {
        $this->db->connection()->transaction(function () use ($inboxId, $newStatus, $errorMessage): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            $currentStatus = self::toString($row->status);
            $this->guardTransition($inboxId, $currentStatus, $newStatus);

            $now = $this->clock->now()->toDateTimeString();
            $update = [
                'status' => $newStatus,
                'error_message' => $errorMessage,
                'updated_at' => $now,
            ];

            // last_scan_at advances ONLY on success-shaped transitions
            // so the UI can surface "stuck since X" for a stalled
            // rate_limited / needs_reauth / error inbox.
            if (in_array($newStatus, ['idle', 'backfilling', 'scanning'], strict: true)) {
                $update['last_scan_at'] = $now;
            }

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update($update);
        });
    }

    public function applyRateLimited(int $inboxId, int $retryAfterSeconds): void
    {
        $this->db->connection()->transaction(function () use ($inboxId, $retryAfterSeconds): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            $currentStatus = self::toString($row->status);
            $this->guardTransition($inboxId, $currentStatus, 'rate_limited');

            $newAttempts = self::toInt($row->retry_attempts) + 1;
            $now = $this->clock->now()->toDateTimeString();

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update([
                    'status' => 'rate_limited',
                    'retry_attempts' => $newAttempts,
                    'error_message' => "Retry after {$retryAfterSeconds}s.",
                    'updated_at' => $now,
                ]);
        });
    }

    public function resetRetryAttempts(int $inboxId): void
    {
        $this->db->connection()->transaction(function () use ($inboxId): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update([
                    'retry_attempts' => 0,
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });
    }

    /**
     * Return the seconds-to-wait for the given attempt index. Clamps
     * to the BACKOFF_SCHEDULE bounds so a runaway retry count cannot
     * walk past the final entry.
     */
    public function backoffForAttempt(int $attempt): int
    {
        $maxIndex = count(self::BACKOFF_SCHEDULE) - 1;
        $idx = max(0, min($maxIndex, $attempt));

        return self::BACKOFF_SCHEDULE[$idx];
    }

    public function recordCursor(int $inboxId, ScanCursor $cursor): void
    {
        if ($cursor->isEmpty()) {
            return;
        }

        $this->db->connection()->transaction(function () use ($inboxId, $cursor): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "InboxScanStateMachine: inbox_scan_state for inbox {$inboxId} folder INBOX not found.",
                );
            }

            // Cross-check the cursor provider against the inbox row's
            // provider. A Gmail cursor must never land on a Microsoft
            // inbox row (and vice versa) — the column choice depends
            // on which provider the inbox speaks, and a mismatch
            // would silently write the wrong cursor column.
            $inboxProvider = self::toString(
                $connection->table('inboxes')->where('id', $inboxId)->value('provider'),
            );
            if ($cursor->provider !== $inboxProvider) {
                throw new InvalidArgumentException(
                    "InboxScanStateMachine: cursor provider '{$cursor->provider}' does not match inbox {$inboxId} provider '{$inboxProvider}'.",
                );
            }

            $update = ['updated_at' => $this->clock->now()->toDateTimeString()];
            if ($cursor->provider === 'gmail' && $cursor->historyId !== null) {
                $update['last_history_id'] = $cursor->historyId;
            } elseif ($cursor->provider === 'microsoft' && $cursor->deltaLink !== null) {
                $update['last_delta_link'] = $cursor->deltaLink;
            }

            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update($update);
        });
    }

    private function guardTransition(int $inboxId, string $currentStatus, string $newStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        if (! in_array($newStatus, $allowed, strict: true)) {
            throw new InvalidStateTransitionException(
                "InboxScanStateMachine: inbox {$inboxId} transition '{$currentStatus}' → '{$newStatus}' is not allowed.",
            );
        }
    }

    /**
     * Numeric coercion for raw query-builder column values. SQLite
     * returns scalars as strings via stdClass attributes; the strict-
     * rules `cast.int` lint stays happy by guarding the int cast.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
