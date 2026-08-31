<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

// Only a backfill that finished cleanly used to clear its counters. One that
// died — a revoked grant, an exhausted retry — kept them, and the inboxes page
// reads their presence as "a backfill is running": a strip polling every two
// seconds, above the same row's "Needs reauth" badge, counting to a total it
// would never reach.
//
// InboxScanStateMachine now clears them on the way out, so this is only for
// the rows already stranded. Nothing a reader can press repairs one — scanNow
// refuses a revoked inbox by design, and the sole other writer is a completed
// OAuth callback, which is the reconnect the strip is obscuring.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('inboxes') || ! $schema->hasTable('inbox_scan_state')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());

        // The INBOX row is where the machine keeps status. An inbox whose
        // folder row is missing has no live backfill either, so a null status
        // falls through the whereNotIn and is repaired with the rest.
        $inFlight = $connection->table('inbox_scan_state')
            ->select('inbox_id')
            ->where('folder', 'INBOX')
            ->whereIn('status', [
                InboxScanStatus::Backfilling->value,
                InboxScanStatus::RateLimited->value,
            ]);

        $connection->table('inboxes')
            ->whereNotNull('backfill_progress')
            ->whereNotIn('id', $inFlight)
            ->update(['backfill_progress' => null]);
    }

    public function down(): void
    {
        // Not reversed. What this cleared was a count of work that had already
        // stopped, and writing one back would restore the strip that lied.
    }
};
