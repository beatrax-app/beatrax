<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schedule;
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

// DiscoveryScanJob daily — Plan 09 will uncomment + extend with the
// real DiscoveryScanJob class once it ships. The commented placeholder
// keeps the Phase 6 scheduler surface visible in one place so the next
// plan can land alongside this hourly entry rather than scattering
// scheduler wiring across the codebase.
//
// Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
//     $userIds = $db->connection()->table('users')->pluck('id');
//     foreach ($userIds as $id) {
//         $bus->dispatch(new \Modules\EmailScan\Internal\Jobs\DiscoveryScanJob((int) $id));
//     }
// })->daily()->name('email-scan.discovery');
