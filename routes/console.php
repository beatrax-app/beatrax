<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Schedule;
use Modules\DriftAlerts\Internal\Jobs\RevivedExpiredDriftSnoozesJob;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;

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
    // Skip inboxes currently in needs_reauth so the hourly tick does
    // not queue jobs that will only early-exit anyway. The job's own
    // first-line guard still handles the case of a row transitioning
    // into needs_reauth between dispatch and pickup; this filter is a
    // multi-user-readiness optimisation — N inboxes per tick where N
    // is the live count, not the total including ones that need user
    // intervention.
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
// — discovery is a metadata-only sweep, the full-body fetch only
// happens for approved senders. The job's ShouldBeUniqueUntilProcessing
// lock keyed on userId collapses a same-day re-dispatch into a single
// queued job.
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
// Cadence matches the email-scan hourly tick (the
// `email-scan.incremental` Schedule entry above) so fetched rows
// surface as canonical transactions within the same wall-clock hour
// they arrive.
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

// Daily recurring-series detection sweep: dispatches
// DetectRecurringSeriesJob once per user per day. The job runs a
// snooze-expiry pass first (flipping snoozed → pending where
// snoozed_until has elapsed), then iterates every container-tagged
// 'recurring.detector' implementation against the user's detection
// window (default 18 months). The job's ShouldBeUniqueUntilProcessing
// lock keyed on userId collapses a same-day re-dispatch (scheduled
// tick or the on-demand button) into a single queued pass.
// Method order .name() BEFORE .daily()->withoutOverlapping(30) —
// CallbackEvent::withoutOverlapping throws LogicException when the
// description is not set yet (same shape as the email-scan + receipts
// entries above).
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()->table('users')->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new DetectRecurringSeriesJob((int) $id));
    }
})->name('recurring.detect')->daily()->withoutOverlapping(30);

// Hourly drift-alert snooze revival sweep: dispatches a single
// RevivedExpiredDriftSnoozesJob that flips `drift_alerts.state` from
// 'snoozed' to 'open' on rows whose `snoozed_until` has elapsed, and
// writes a transition row with reason='detector_revived_snooze'. The
// sweep is global (no per-user fan-out) because alerts may belong to
// any user and the state-machine call resolves the user_id from the
// row itself.
// Method order .name() BEFORE .hourly()->withoutOverlapping() matches
// the email-scan + receipts + recurring entries above so the
// CallbackEvent's description-required guard is satisfied before
// withoutOverlapping reads it.
// The companion DriftAlertQuery::openForUser query-time conditional
// surfaces snoozed-but-expired rows immediately between sweeps so the
// dashboard count + sum stay honest without waiting for the hour tick.
Schedule::call(function (Dispatcher $bus): void {
    $bus->dispatch(new RevivedExpiredDriftSnoozesJob);
})->name('drift-alerts.revive-snoozes')->hourly()->withoutOverlapping(30);
