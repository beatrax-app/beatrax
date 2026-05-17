<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schedule;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

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
    $inboxIds = $db->connection()->table('inboxes')->pluck('id');
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
