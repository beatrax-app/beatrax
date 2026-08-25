<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Schedule;
use Modules\Anomaly\Internal\Jobs\ReviveExpiredAnomalySnoozesJob;
use Modules\Anomaly\Internal\Jobs\SafetyNetAnomalySweepJob;
use Modules\Budgets\Internal\Jobs\EmitBudgetNudgesJob;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob;
use Modules\DriftAlerts\Internal\Jobs\EmitSavingsPromptsJob;
use Modules\DriftAlerts\Internal\Jobs\RevivedExpiredDriftSnoozesJob;
use Modules\EmailScan\Internal\Jobs\DetectIcsStatementReadyJob;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;
use Modules\Notifications\Internal\Jobs\PruneNotificationsJob;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\Position\Internal\Jobs\EmitPositionDigestJob;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Internal\Jobs\EmitPaymentRemindersJob;

// App-wide artisan command bindings live here. Module-local artisan
// commands are registered from each module's ServiceProvider.

// Shared daily slot for the user-notification schedules (reminders,
// digest, savings prompts): deliberately after the 09:00 FX refresh so
// every converted figure reflects the day's already-fresh rate.
$notificationsDailyTime = '09:15';

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

// Hourly anomaly snooze-revival sweep: dispatches a single
// ReviveExpiredAnomalySnoozesJob that flips `anomaly_alerts.state` from
// 'snoozed' to 'open' on rows whose `snoozed_until` has elapsed and
// writes a transition row with reason='detector_revived_snooze' (Pattern
// 4, cloned from drift-alerts.revive-snoozes). The sweep is global (no
// per-user fan-out) — the state machine resolves the user_id from the row.
// Method order .name() BEFORE .hourly()->withoutOverlapping() so the
// CallbackEvent's description-required guard is satisfied before
// withoutOverlapping reads it.
// The companion AnomalyAlertQuery::openForUser query-time conditional
// surfaces snoozed-but-expired rows immediately between sweeps so the
// dashboard count + list stay honest without waiting for the hour tick.
Schedule::call(function (Dispatcher $bus): void {
    $bus->dispatch(new ReviveExpiredAnomalySnoozesJob);
})->name('anomaly.revive-snoozes')->hourly()->withoutOverlapping(30);

// Hourly anomaly safety-net sweep: fans out one SafetyNetAnomalySweepJob
// per user, each re-evaluating that user's recently-imported-but-unalerted
// transactions through the shared AnomalyEvaluator path — catching any
// charge the reactive TransactionImported listener missed (a safety
// net). The job's ShouldBeUniqueUntilProcessing lock keyed on userId
// collapses a same-hour re-dispatch into a single queued sweep; the
// withoutOverlapping(30) guard prevents this closure from racing a prior
// tick. Method order .name() BEFORE .hourly()->withoutOverlapping() —
// CallbackEvent::withoutOverlapping throws LogicException otherwise.
// User iteration via ->lazyById(100) so a multi-user deployment does not
// load every user row into memory upfront (mirrors forecasting + fx).
Schedule::call(function (Dispatcher $bus): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus): void {
        $bus->dispatch(new SafetyNetAnomalySweepJob($user->id));
    });
})->name('anomaly.safety-net-sweep')->hourly()->withoutOverlapping(30);

// Daily forecast projection sweep: fans out one baseline
// ProjectForecastJob dispatch per horizon in
// ProjectForecastJob::HORIZON_DAYS per user (a later extension
// added two long horizons, so sizing queue load from a hardcoded
// horizon list here would undercount). The job's
// ShouldBeUniqueUntilProcessing lock keyed on
// (userId, 'baseline', horizonDays) collapses any same-day duplicate
// trigger into a single queued run; the withoutOverlapping(30) guard
// here prevents this scheduler closure from racing with a previous
// tick. The inner loop also dispatches per saved scenario.
// Closure DI mirrors the email-scan + receipts + recurring entries
// above — Bus Dispatcher resolved through the container; no facade
// reaches into module code. Method order .name() BEFORE
// .daily()->withoutOverlapping(30) — CallbackEvent's description-
// required guard fires otherwise.
//
// User iteration via ->lazyById(100) so a multi-user deployment does
// not load every user row into memory upfront.
Schedule::call(function (Dispatcher $bus, ScenarioQuery $scenarioQuery): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus, $scenarioQuery): void {
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
            $bus->dispatch(new ProjectForecastJob(
                userId: $user->id,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }
        foreach ($scenarioQuery->forUser($user) as $scenario) {
            foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
                $bus->dispatch(new ProjectForecastJob(
                    userId: $user->id,
                    scenarioId: $scenario->id,
                    horizonDays: $horizon,
                ));
            }
        }
    });
})->name('forecasting.daily-sweep')->daily()->withoutOverlapping(30);

// Daily FX rate refresh: fans out one FetchFxRatesJob per user who has
// explicitly opted into online rate fetching (fx_online_enabled = true).
// Rate providers are ECB → Frankfurter → bundled snapshot.
// Only opted-in users trigger outbound HTTP requests; local-only users
// never see external network calls from this entry (local-only ethos).
//
// The job's ShouldBeUniqueUntilProcessing lock keyed on userId collapses
// any same-day re-dispatch (scheduled tick + on-demand refresh) into a
// single queued run per user.
//
// 09:00 window: ECB publishes reference rates at ~16:00 CET (previous
// business day's data is always available by 09:00 the next morning),
// so this cadence ensures fresh rates for the day's first dashboard load.
//
// Method order .name() BEFORE .dailyAt() BEFORE .withoutOverlapping(30)
// — Laravel's CallbackEvent::withoutOverlapping reads the description
// (set by .name()) to derive the mutex name and throws LogicException
// when the description has not been set yet (same shape as every other
// entry above).
//
// User iteration via ->lazyById(100) so a multi-user deployment does not
// load every user row into memory at once.
Schedule::call(function (Dispatcher $bus): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus): void {
        if ((bool) $user->fx_online_enabled) {
            $bus->dispatch(new FetchFxRatesJob($user->id));
        }
    });
})->name('fx.daily-refresh')->dailyAt('09:00')->withoutOverlapping(30);

// Daily SQLite backup: produces a verified VACUUM-INTO snapshot under
// storage/app/backups/, applies the retention sweep (7 newest dailies +
// 4 most-recent Sundays), and writes a system_alerts(backup_corrupt)
// row on integrity failure. The Schedule facade lives at the project
// root in routes/console.php — outside the Modules\ namespace — so the
// BoundaryArchTest "no Laravel facade usage in module code" rule does
// not apply here (the rule is scoped via ->not->toBeUsedIn('Modules')).
//
// 03:00 wall-clock window: late enough that an interactive session
// (local dev + Livewire dev loop) has stopped writing for the day; early
// enough that a launchd-fired schedule:work picks it up well before
// the next morning's first dashboard load.
//
// Method order .name() BEFORE .dailyAt('03:00')->withoutOverlapping(60)
// matches every other entry in this file — Laravel's CallbackEvent
// `withoutOverlapping` reads the event's description to derive the
// mutex name and throws LogicException when the description has not
// been set yet.
//
// Lock TTL of 60 minutes (NOT the default 1440 minutes): a 5-second
// backup that is still "running" an hour later was certainly killed
// mid-flight, and a stuck mutex that survives 24 hours would block
// every subsequent scheduled run silently. A 60-minute TTL recovers
// automatically while still spanning the longest realistic backup
// duration on a multi-gigabyte personal-finance DB.
//
// `--force` bypasses the smart-skip so a quiet day
// (operator never opened the dashboard, no commits to the DB) still
// produces a fresh sidecar. Without --force, two consecutive quiet
// days would write no .meta.json file and cross the 48h
// BackupFreshnessProbe::STALE_AFTER_HOURS threshold, producing a
// false-positive `backup_overdue` banner that no follow-up
// `db:backup` would clear, because a quiet day is exactly what the
// skip is for. The retention sweep prunes any genuine duplicates so
// the disk footprint is bounded.
Schedule::command('db:backup --force')
    ->name('db.backup-daily')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);

// Daily counterparty garbage-collector sweep: dispatches a per-user
// CounterpartyGarbageCollectorJob at 04:00 Europe/Amsterdam — one
// hour after the daily backup so a freshly-pruned counterparty set
// is captured in the next morning's backup snapshot. The job's
// ShouldBeUniqueUntilProcessing lock keyed on userId collapses a
// same-day re-dispatch into a single queued pass; the orphan
// predicate keeps counterparties with recent transactions or an
// alias anchor.
//
// Closure DI mirrors the email-scan + receipts + recurring entries
// above — Bus Dispatcher resolved through the container; no facade
// reaches into module code. Method order .name() BEFORE
// .dailyAt('04:00')->timezone('Europe/Amsterdam')
// ->withoutOverlapping(30) matches the existing entries' shape so
// CallbackEvent's description-required guard reads the description
// before withoutOverlapping consumes it.
Schedule::call(function (Dispatcher $bus): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus): void {
        $bus->dispatch(new CounterpartyGarbageCollectorJob($user->id));
    });
})
    ->name('counterparties.gc')
    ->dailyAt('04:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping(30);

// The five notifications-and-reminders schedule entries: four proactive
// triggers (payment reminders, position digest, budget nudges, savings
// prompts) plus the notification-inbox retention sweep. All five
// fan out one job per user via ->lazyById(100), mirroring every per-user
// entry above.
//
// `routes/console.php` is application-level wiring, outside the `Modules\`
// namespace, so it is the ONE sanctioned place allowed to read
// `Modules\Notifications`' `NotificationPreferenceQuery` AND dispatch a
// trigger module's job in the same closure — this is why
// `EmitPaymentRemindersJob` takes `$leadDays` and `EmitPositionDigestJob`
// takes `$cadence` as constructor parameters rather than reading
// `NotificationPreferenceQuery` themselves: reading it from inside
// `Modules\Recurring` / `Modules\Position` would break the invariant that
// no trigger module may import `Modules\Notifications`. Bridging here
// keeps both of those modules wholly ignorant of notification delivery.
//
// Trigger toggles (reminders/budget-nudges/savings-prompts enabled,
// digest-cadence) are DELIBERATELY not consulted here to decide whether to
// dispatch — `SuppressionEvaluator::shouldDeliver()` is the ONE place
// delivery suppression is decided, and it always stores
// the row even when a trigger is toggled off; only the OS/mobile push is
// suppressed. Gating dispatch on a toggle here would silently stop rows
// from ever being written for that trigger, which no other suppression
// path assumes, and the inbox-first contract would then be violated for
// a disabled trigger's own history.
Schedule::call(function (Dispatcher $bus, NotificationPreferenceQuery $prefs): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus, $prefs): void {
        $leadDays = $prefs->forCurrentDevice($user)->reminderLeadDays;
        $bus->dispatch(new EmitPaymentRemindersJob($user->id, $leadDays));
    });
})->name('notifications.reminders')->dailyAt($notificationsDailyTime)->withoutOverlapping(30);

// 09:15 — deliberately AFTER `fx.daily-refresh` (09:00 above): any
// converted figure the digest shows uses the day's already-fresh FX rate
// rather than yesterday's. The same 09:00-then-09:15 reasoning applies to
// `notifications.savings-prompts` below. `$cadence` is resolved per
// user/device from `NotificationPreferenceQuery::forCurrentDevice()`; a
// cadence of 'off' still dispatches unconditionally —
// `EmitPositionDigestJob` itself returns immediately on 'off' (18-09),
// keeping that one decision in the single place that already owns it
// rather than duplicating an off-check here too.
Schedule::call(function (Dispatcher $bus, NotificationPreferenceQuery $prefs): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus, $prefs): void {
        $cadence = $prefs->forCurrentDevice($user)->digestCadence->value;
        $bus->dispatch(new EmitPositionDigestJob($user->id, $cadence));
    });
})->name('notifications.digest')->dailyAt($notificationsDailyTime)->withoutOverlapping(30);

// Hourly (not daily) so "you're at 90%" arrives near the spend that
// actually crossed the threshold rather than up to a day later. The
// per-period occurrence key already prevents the same crossing from
// re-firing on the next tick within the same budget period.
Schedule::call(function (Dispatcher $bus): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus): void {
        $bus->dispatch(new EmitBudgetNudgesJob($user->id));
    });
})->name('notifications.budget-nudges')->hourly()->withoutOverlapping(30);

// 09:15 — same 09:00-FX-then-09:15 reasoning as `notifications.reminders`
// and `notifications.digest` above.
Schedule::call(function (Dispatcher $bus): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus): void {
        $bus->dispatch(new EmitSavingsPromptsJob($user->id));
    });
})->name('notifications.savings-prompts')->dailyAt($notificationsDailyTime)->withoutOverlapping(30);

// 04:30 — the notification-inbox retention sweep. 04:30
// deliberately sits between the two other daily-maintenance windows
// already claimed above — `db:backup` at 03:00 and `counterparties.gc` at
// 04:00 — so the prune never contends with either for the SQLite
// single-writer lock. `PruneNotificationsJob` itself needs no KEK:
// its predicate keys solely on the always-plaintext `created_at` column,
// never `title`/`body`/`params`/`trigger_type`, so the sweep stays bounded
// even on a usually-locked or headless device.
Schedule::call(function (Dispatcher $bus): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus): void {
        $bus->dispatch(new PruneNotificationsJob($user->id));
    });
})->name('notifications.prune')->dailyAt('04:30')->withoutOverlapping(30);

// Daily open-banking auto-sync: fans out one
// SyncOpenBankingAccountJob per connection that is BOTH enabled AND has a
// non-expired consent — the "no-op when OB is off or consent has expired"
// requirement is enforced HERE, at this enumeration
// query's WHERE clause, not solely inside the job. `SyncOpenBankingAccountJob::
// handle()` additionally re-checks both conditions defensively on pickup
// (mirrors `IncrementalScanJob`'s "Early exit #1" needs_reauth check) for the
// same crash-between-dispatch-and-pickup race that entry's docblock
// documents — a connection that is disabled or whose consent expires between
// this tick's enumeration and the worker actually picking up the job must
// still result in zero fetch attempts and zero timestamp writes.
//
// The job's ShouldBeUniqueUntilProcessing lock (uniqueId=connectionId,
// uniqueFor=600) collapses a same-window duplicate dispatch (this daily tick
// racing a manual "Sync now" click) into a single in-flight fetch.
//
// Closure DI mirrors every other entry above — DatabaseManager + Bus
// Dispatcher resolved through the container; no facade reaches into module
// code. Method order .name() BEFORE .dailyAt('06:00') BEFORE
// .withoutOverlapping(30) matches every other entry in this file —
// CallbackEvent::withoutOverlapping throws LogicException when the
// description has not been set yet.
//
// 06:00 window: ahead of the 09:00 FX refresh and the 09:15
// notifications/digest entries above, so any dashboard figure computed from
// a freshly-synced OB transaction on the same morning already reflects it.
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $connectionIds = $db->connection()
        ->table('open_banking_connections')
        ->where('enabled', true)
        ->whereNotNull('consent_expires_at')
        ->where('consent_expires_at', '>', now())   // no-op when consent has expired
        ->pluck('id');
    foreach ($connectionIds as $id) {
        $bus->dispatch(new SyncOpenBankingAccountJob((int) $id));
    }
})->name('open-banking.daily-sync')->dailyAt('06:00')->withoutOverlapping(30);
