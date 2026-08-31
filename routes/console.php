<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Schedule;
use Modules\Core\Public\Scheduling\DailyLocalWindow;
use Modules\Core\Public\Scheduling\ScheduleRegistrationGuard;
use Modules\EmailScan\Internal\Jobs\DetectIcsStatementReadyJob;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\Notifications\Internal\Console\EmitDailyNotificationTriggersCommand;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;

// TWO LOADERS REACH THIS FILE AND ONLY ONE OF THEM IS IDEMPOTENT.
//
// `Illuminate\Foundation\Console\Kernel::discoverCommands()` plain-`require`s
// every path `withRouting(commands:)` names, and on the mobile Composer root
// nativephp/mobile-background-tasks has already `require_once`d the same path
// from a `resolving(Schedule::class)` hook — the phone is served over HTTP and
// never runs command discovery, so that package cannot rely on it. Two include
// mechanisms, one file, and its body ran twice.
//
// Android WorkManager replaces a task by unique name, so the duplicates were
// invisible there. iOS `BGTaskScheduler.register` throws an uncaught
// NSInternalInconsistencyException on the second handler for one identifier,
// which aborts the app before its first frame: twelve doubled identifiers made
// the iPhone build unlaunchable.
//
// See `.docs/features/mobile/architecture.md`, "Two loaders reach the
// console routes and only one is idempotent".
if (! ScheduleRegistrationGuard::firstLoad(__FILE__)) {
    return;
}

// App-wide artisan command bindings live here. Module-local artisan
// commands are registered from each module's ServiceProvider.
//
// TWO SHAPES LIVE IN THIS FILE, AND THE DIFFERENCE IS NOT STYLE.
//
// `Schedule::command()` is the only shape a phone can run. NativePHP's mobile
// background runner re-launches the app and invokes an artisan name through
// Android WorkManager / iOS BGTaskScheduler; a `Schedule::call()` closure has
// no name to invoke, and a cron the runner has no repeat interval for — every
// `dailyAt()` — is dropped from its manifest without failing anything. Twenty
// of this file's twenty-one entries were being dropped on a real phone.
//
// So everything the phone must run is a command whose body lives in the module
// that owns the work, scheduled here on an expression from
// `Modules\Core\Public\Scheduling\MobileBackgroundSchedule::RUNNER_INTERVALS`.
// The closures that remain are the inbox-fetch pipeline, which is desktop-only
// by decision, not by accident: `MobileBackgroundSchedule::desktopOnly()`
// carries the reason for each, and `beatrax:doctor` fails when anything
// outside that list stops reaching the manifest.
//
// See `.docs/features/mobile/architecture.md`, "The phone runs an artisan name
// on an interval, and nothing else".

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

// Desktop-shell timer-based email auto-scan. The shipped
// NativePHP bundle has no always-on IMAP-idle daemon (that stays a
// dev-box launchd concern); instead the scheduler — which runs
// automatically inside a NativePHP app per the bundle scheduler
// pattern — fires this entry every ~15 minutes so the non-technical
// partner sees fresh receipts within a quarter-hour without manual
// action. Cadence is shorter than the hourly `email-scan.incremental`
// entry above on purpose: the bundle's only background-work signal
// is this timer, and the partner has no Horizon / no dev console to
// confirm activity. The job's ShouldBeUniqueUntilProcessing lock
// (uniqueId=inboxId, uniqueFor=600) collapses any same-window
// duplicate so a 15-min cadence racing the 60-min cadence is
// harmless. Method order .name() BEFORE .everyFifteenMinutes()
// BEFORE .withoutOverlapping(10) matches every other entry in this
// file so Laravel CallbackEvent::withoutOverlapping reads the
// description before throwing LogicException.
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    // Skip inboxes currently in needs_reauth so the timer tick does
    // not queue jobs that will only early-exit. Mirrors the hourly
    // entry above.
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
})->name('desktop.email-scan.timer')->everyFifteenMinutes()->withoutOverlapping(10);

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

// Hourly ICS "statement ready" nudge detector:
// dispatches DetectIcsStatementReadyJob per user. The job reads ONLY
// inbox_messages.sender_email/.subject (metadata-only, never the .eml
// body) and dispatches Modules\EmailScan\Public\Events\IcsStatementReady
// for each match; Modules\Notifications\Internal\Listeners\PersistIcsStatementReady
// persists the once-per-statement-month nudge. Cadence matches the
// email-scan + receipts entries above so a statement-ready email surfaces
// within the same wall-clock hour it is fetched. Method order .name()
// BEFORE .hourly()->withoutOverlapping(30) matches every other entry in
// this file (CallbackEvent::withoutOverlapping throws LogicException if
// description isn't set first).
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()->table('users')->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new DetectIcsStatementReadyJob((int) $id));
    }
})->name('email-scan.detect-ics-statement-ready')->hourly()->withoutOverlapping(30);

// Watched-folder secondary path over storage/app/inbox-drop/{userId}/,
// importing .eml / .mbox through the same matcher pipeline as the wizard
// upload.
//
// It was a closure, and the settings copy told a phone reader it ran every
// five minutes. A closure has no artisan name for the runner to invoke, so it
// never entered the device manifest, and nothing else dispatches the job: the
// toggle was live on the phone and switched on a scan that could not happen.
// Five minutes stays because it is the desktop's real cadence; the runner
// clamps it to its fifteen-minute floor and the copy says so per platform.
Schedule::command('receipts:scan-drop-folder')
    ->name('receipts.scan-drop-folder')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Daily recurring-series detection sweep. The command runs a snooze-expiry
// pass first (flipping snoozed → pending where snoozed_until has elapsed),
// then iterates every container-tagged 'recurring.detector' implementation
// against the user's detection window.
Schedule::command('recurring:detect')
    ->name('recurring.detect')
    ->daily()
    ->withoutOverlapping(30);

// Hourly snooze-revival sweeps: each flips its module's alert rows from
// 'snoozed' to 'open' where snoozed_until has elapsed, and writes a
// transition row with reason='detector_revived_snooze'.
Schedule::command('drift-alerts:revive-snoozes')
    ->name('drift-alerts.revive-snoozes')
    ->hourly()
    ->withoutOverlapping(30);

Schedule::command('anomaly:revive-snoozes')
    ->name('anomaly.revive-snoozes')
    ->hourly()
    ->withoutOverlapping(30);

Schedule::command('anomaly:safety-net-sweep')
    ->name('anomaly.safety-net-sweep')
    ->hourly()
    ->withoutOverlapping(30);

// Daily forecast projection sweep: baseline plus every saved scenario, at
// every horizon, per user. The job's ShouldBeUniqueUntilProcessing lock keyed
// on (userId, 'baseline', horizonDays) collapses any same-day duplicate.
Schedule::command('forecasting:project')
    ->name('forecasting.daily-sweep')
    ->daily()
    ->withoutOverlapping(30);

// Daily FX rate refresh. It used to run at 09:00 so the day's first dashboard
// load saw fresh rates; the runner cannot express an hour, and the hour was
// never load-bearing — ECB publishes at ~16:00 CET, so a midnight fetch reads
// the very publication 09:00 would have read, and still lands before the
// 09:15 notification pass below.
Schedule::command('fx:refresh-rates')
    ->name('fx.daily-refresh')
    ->daily()
    ->withoutOverlapping(30);

// Daily SQLite backup: a verified VACUUM-INTO snapshot under
// storage/app/backups/, the retention sweep (7 newest dailies + 4 most-recent
// Sundays), and a system_alerts(backup_corrupt) row on integrity failure.
//
// It used to run at 03:00, "late enough that an interactive session has
// stopped writing". VACUUM INTO only reads, and WAL readers never block a
// writer, so the quiet hour was a nicety — and the cost of keeping it was that
// a phone, which may be the only device holding this data, never backed up at
// all. `--force` bypasses the smart-skip so a quiet day still writes a fresh
// sidecar and no false `backup_overdue` banner appears.
//
// Lock TTL of 60 minutes, NOT the default 1440: a five-second backup still
// "running" an hour later was killed mid-flight, and a stuck mutex surviving a
// day would silently block every subsequent run.
Schedule::command('db:backup --force')
    ->name('db.backup-daily')
    ->daily()
    ->withoutOverlapping(60);

// Daily counterparty garbage collection. It used to run at 04:00
// Europe/Amsterdam, an hour after the backup, so a freshly-pruned set was
// captured in the next morning's snapshot — which still holds when both run
// daily and this entry is defined after the backup. The explicit timezone is
// gone with the hour: it matched APP_TIMEZONE, and a repeat interval on a
// phone has no timezone to honour.
Schedule::command('counterparties:collect-garbage')
    ->name('counterparties.gc')
    ->daily()
    ->withoutOverlapping(30);

// The daily user-notification pass — payment reminders, position digest and
// savings prompts, three entries that always shared one 09:15 slot.
//
// 09:15 IS load-bearing here in a way the other daily hours were not: these
// are pushes. A digest at midnight is a different product, and
// SuppressionEvaluator's quiet hours would swallow it entirely, so the row
// would be written and never delivered. The runner cannot express a wall clock
// at all, so the clock moved into the command: it ticks on a supported
// fifteen-minute interval and DailyLocalWindow lets exactly one tick per local
// day through, at or after 09:15.
//
// The ->when() filter asks the same window read-only. On the desktop that is
// what keeps a fifteen-minute entry spawning one artisan process a day rather
// than ninety-six; the phone never evaluates a schedule filter, so the command
// re-asks and claims for itself.
Schedule::command('notifications:daily-triggers')
    ->name('notifications.daily-triggers')
    ->everyFifteenMinutes()
    ->when(static fn (DailyLocalWindow $window): bool => $window->isDue(
        EmitDailyNotificationTriggersCommand::WINDOW_KEY,
        EmitDailyNotificationTriggersCommand::LOCAL_TIME,
    ))
    ->withoutOverlapping(30);

// Hourly, not daily, so "you're at 90%" arrives near the spend that actually
// crossed the threshold. The per-period occurrence key already stops the same
// crossing re-firing on the next tick within the same budget period.
Schedule::command('budgets:emit-nudges')
    ->name('notifications.budget-nudges')
    ->hourly()
    ->withoutOverlapping(30);

// The notification-inbox retention sweep. It used to run at 04:30 purely to
// sit clear of the 03:00 backup and 04:00 GC for the SQLite single-writer
// lock; with all three daily the scheduler already serialises them in one
// process, and its predicate keys solely on the always-plaintext created_at
// column, so it stays bounded on a locked or headless device.
Schedule::command('notifications:prune')
    ->name('notifications.prune')
    ->daily()
    ->withoutOverlapping(30);

// Daily open-banking auto-sync. It used to run at 06:00 to sit ahead of the FX
// refresh and the notification pass; it still does, defined after the FX entry
// and firing hours before the 09:15 window. Only connections that are BOTH
// enabled AND hold a non-expired consent are enumerated — the "no-op when OB
// is off or consent has expired" requirement is enforced at that query, not
// solely inside the job.
Schedule::command('open-banking:sync-due')
    ->name('open-banking.daily-sync')
    ->daily()
    ->withoutOverlapping(30);
