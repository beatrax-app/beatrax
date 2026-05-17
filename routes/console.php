<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Schedule;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;

// App-wide artisan command bindings live here. Module-local artisan
// commands are registered from each module's ServiceProvider.

// Hourly incremental scan: enumerate every connected inbox across
// every user and dispatch IncrementalScanJob for each id. The job's
// ShouldBeUniqueUntilProcessing lock (uniqueId=inboxId, uniqueFor=600)
// keeps a duplicate dispatch harmless if Horizon is slow to pick up
// the previous tick. The withoutOverlapping(30) guard prevents this
// scheduler closure itself from overlapping with a prior tick — at
// the per-minute scheduler frequency it should never come close, but
// the guard is cheap insurance.
//
// The closure receives both the DatabaseManager and the Bus
// Dispatcher via Laravel's container — no facade or global helper
// reaches into module code (CLAUDE.md `feedback_laravel_di_only.md`
// is honoured). The Schedule facade itself lives at the project
// root in routes/console.php, outside the `Modules\` namespace, so
// the BoundaryArchTest "no Laravel facade usage in module code"
// rule does not apply here (the rule is scoped via
// `->not->toBeUsedIn('Modules')`).
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    // IN-02 iter-2: skip inboxes currently in needs_reauth so the
    // hourly tick does not queue jobs that will only early-exit
    // anyway. The job's own first-line guard still handles the
    // case of a row transitioning into needs_reauth between dispatch
    // and pickup; this filter is a multi-user-readiness optimisation
    // — N inboxes per tick where N is the live count, not the total
    // including ones that need user intervention.
    $inboxIds = $db->connection()
        ->table('inboxes')
        ->leftJoin('inbox_scan_state', function (JoinClause $join): void {
            $join->on('inbox_scan_state.inbox_id', '=', 'inboxes.id')
                ->where('inbox_scan_state.folder', '=', 'INBOX');
        })
        ->where(function (Builder $q): void {
            $q->whereNull('inbox_scan_state.status')
                ->orWhere('inbox_scan_state.status', '!=', 'needs_reauth');
        })
        ->pluck('inboxes.id');
    foreach ($inboxIds as $id) {
        $bus->dispatch(new IncrementalScanJob((int) $id));
    }
})->name('email-scan.incremental')->hourly()->withoutOverlapping(30);

// Daily discovery scan: dispatch DiscoveryScanJob once per user per
// day. The job walks every connected inbox with a broad keyword
// query, populates discovered_senders, and never persists .eml blobs
// (D-121). The job's ShouldBeUniqueUntilProcessing lock keyed on
// userId collapses a same-day re-dispatch into a single queued job.
// Closure DI mirrors the hourly entry above — DatabaseManager + Bus
// Dispatcher via the container; no facade reaches into module code.
// Method order .name() BEFORE .daily()->withoutOverlapping() — Laravel
// CallbackEvent::withoutOverlapping (line 141) throws LogicException
// when description is not set yet (same shape as the hourly entry).
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()->table('users')->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new DiscoveryScanJob((int) $id));
    }
})->name('email-scan.discovery')->daily()->withoutOverlapping(30);

// Hourly matcher consumer: walks inbox_messages.status='fetched' for
// each user, transitions each row to parsed / skipped / unmatched
// via the shared RecordReceipt action. The job's
// ShouldBeUniqueUntilProcessing lock keyed on userId collapses a
// same-hour re-dispatch into a single queued job; the withoutOverlapping
// guard prevents this scheduler closure from racing with itself.
// Cadence matches Phase 6's incremental scan hourly tick so fetched
// rows surface as canonical transactions within the same wall-clock
// hour they arrive.
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()->table('users')->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new ProcessFetchedInboxMessagesJob((int) $id));
    }
})->name('receipts.process-fetched-inbox-messages')->hourly()->withoutOverlapping(30);

// Watched-folder secondary path: per-user 5-minute scanner over
// storage/app/inbox-drop/{userId}/ that imports .eml / .mbox files
// through the same matcher pipeline as the wizard upload. Only
// dispatches per-user when auto_import_drop_folder is true so users
// who never enabled the watched folder pay no queue cost. Method
// order .name() BEFORE .everyFiveMinutes()->withoutOverlapping(10)
// matches the email-scan + receipts entries above so the
// CallbackEvent's description-required guard is satisfied before
// withoutOverlapping reads it.
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()
        ->table('users')
        ->where('auto_import_drop_folder', true)
        ->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new ScanInboxDropFolderJob((int) $id));
    }
})->name('receipts.scan-drop-folder')->everyFiveMinutes()->withoutOverlapping(10);
