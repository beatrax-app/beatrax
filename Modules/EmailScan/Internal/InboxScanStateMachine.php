<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\ScanCursor;

/**
 * The single legal mutator of `inbox_scan_state.status` and the
 * provider cursor columns (`last_history_id`, `last_delta_link`). A
 * BoundaryArchTest invariant under `tests/Contracts/BoundaryArchTest.php`
 * blocks every other write path under `Modules/EmailScan/`.
 *
 * This first revision of the state machine carries only the two
 * narrow write surfaces the backfill plan needs:
 *
 *  - `applyStatus()` writes the per-row status string, the optional
 *    error_message, and the last_scan_at timestamp. The trigger pair
 *    on the table enforces the enum, so an invalid status raises
 *    SQLITE_ABORT before any row mutates.
 *
 *  - `recordCursor()` writes whichever cursor column matches the
 *    cursor's provider — Gmail's history id or Microsoft's delta
 *    link. Passing an empty ScanCursor is a no-op so callers can
 *    funnel "we did not learn a new cursor" through the same gate
 *    as a real write.
 *
 * Both writes promote `PRAGMA busy_timeout = 5000` inside the
 * transaction so two concurrent backfill jobs that briefly contend
 * on the SQLite writer lock wait up to five seconds before raising
 * SQLITE_BUSY (RESEARCH Pitfall 6).
 *
 * A later plan extends this class with the full transition-validation
 * matrix + retry_attempts bookkeeping + lockForUpdate semantics; this
 * stub is the minimum surface the chunked BackfillInboxJob needs to
 * funnel its status flips and end-of-walk cursor through.
 */
final class InboxScanStateMachine
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function applyStatus(
        int $inboxId,
        string $newStatus,
        ?string $errorMessage = null,
    ): void {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        $connection->transaction(function () use ($connection, $inboxId, $newStatus, $errorMessage, $now): void {
            $connection->statement('PRAGMA busy_timeout = 5000');
            $connection->table('inbox_scan_state')
                ->where('inbox_id', $inboxId)
                ->where('folder', 'INBOX')
                ->update([
                    'status' => $newStatus,
                    'error_message' => $errorMessage,
                    'last_scan_at' => $now,
                    'updated_at' => $now,
                ]);
        });
    }

    public function recordCursor(int $inboxId, ScanCursor $cursor): void
    {
        if ($cursor->isEmpty()) {
            return;
        }

        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        $connection->transaction(function () use ($connection, $inboxId, $cursor, $now): void {
            $connection->statement('PRAGMA busy_timeout = 5000');

            $update = ['updated_at' => $now];
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
}
