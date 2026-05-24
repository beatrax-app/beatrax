<?php

declare(strict_types=1);

use App\Providers\HorizonServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\DevMode\Internal\Http\Controllers\AdvancedToggleController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanCancelController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanSpawnController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanStreamController;
use Modules\DevMode\Internal\Http\Controllers\DestructiveSpawnController;
use Modules\DevMode\Internal\Http\Controllers\LogStreamController;
use Modules\DevMode\Internal\Http\Middleware\HorizonFrameAncestors;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Internal\Http\Livewire\DevOverviewPage;
use Modules\DevMode\Internal\Http\Livewire\DoctorPanelPage;
use Modules\DevMode\Internal\Http\Livewire\HorizonFramePage;
use Modules\DevMode\Internal\Http\Livewire\LogTailerPage;
use Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage;
use Modules\DevMode\Internal\Http\Livewire\SqlPanelPage;
use Modules\DevMode\Internal\Http\Livewire\SystemSnapshotPage;

/*
 * Dev Console routes. Mounted by DevModeServiceProvider via
 * `loadRoutesFrom(__DIR__.'/../Routes/web.php')`.
 *
 * Every `/dev/*` route applies the `ensureDeveloperMode` middleware
 * alias registered in DevModeServiceProvider::boot(). The arch
 * invariant `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`
 * (in tests/Contracts/BoundaryArchTest.php) locks the coverage at PR
 * time: a future plan that adds a new `/dev/*` route without the
 * alias fails CI.
 *
 * Downstream Wave 4 / Wave 5 / Wave 6 / Wave 7 plans (16-04 through
 * 16-07) append routes inside the same `->group(...)` (artisan
 * runner, audit, logs, queue, doctor, sql, horizon, system). When
 * each route lands the matching sidebar `nav-disabled` class drops
 * off (the dev-shell layout's `Route::has(...)` check resolves the
 * existence).
 */
Route::middleware(['web', 'auth', 'ensureDeveloperMode'])
    ->prefix('/dev')
    ->group(static function (): void {
        Route::get('/', DevOverviewPage::class)->name('dev.overview');

        // 16-04b: Artisan runner page + Audit log page (dev.artisan +
        // dev.audit). When these routes resolve, the dev-shell layout's
        // Route::has() check automatically drops the `nav-disabled`
        // class from the Artisan + Audit sidebar items (16-03 wired
        // the per-item Route::has check).
        Route::get('/artisan', ArtisanRunnerPage::class)->name('dev.artisan');
        Route::get('/audit', AuditLogPage::class)->name('dev.audit');

        // 16-04: SAFE-tier spawn pipeline. The runner UI page +
        // DESTRUCTIVE triple-gate land in 16-04b; only the JSON +
        // SSE endpoints are exposed here.
        Route::post('/artisan/spawn', ArtisanSpawnController::class)
            ->name('dev.artisan.spawn');
        Route::get('/artisan/stream/{runId}', ArtisanStreamController::class)
            ->name('dev.artisan.stream');
        Route::post('/artisan/cancel/{runId}', ArtisanCancelController::class)
            ->name('dev.artisan.cancel');

        Route::post('/advanced-toggle', AdvancedToggleController::class)
            ->name('dev.advanced-toggle');

        // 16-04b: DESTRUCTIVE-tier spawn endpoint. SEPARATE from the
        // SAFE-tier ArtisanSpawnController above; this controller
        // RE-VALIDATES the triple-gate (env + session + typed) before
        // routing through CommandSpawner::start(..., 'destructive').
        Route::post('/artisan/destructive-spawn', DestructiveSpawnController::class)
            ->name('dev.artisan.destructive-spawn');

        // 16-05: Log tailer page + SSE stream + ±10-line context lookup
        // (CONTEXT D-28 / D-29 / D-31). The page mounts the Alpine
        // ring-buffer + EventSource consumer; the SSE controller re-
        // applies RedactSecretsProcessor (defense-in-depth on top of
        // the on-write Monolog tap installed in 16-04b).
        Route::get('/logs', LogTailerPage::class)->name('dev.logs');
        Route::get('/logs/stream', LogStreamController::class)->name('dev.logs.stream');
        Route::get('/logs/context', [LogStreamController::class, 'context'])->name('dev.logs.context');

        // 16-06: Queue inspector (CONTEXT D-32). Three sub-routes back
        // the same Livewire component; the bare `/dev/queue` URL
        // redirects to the default `pending` tab so deep-link
        // ergonomics stay intact. The canonical route name is
        // `dev.queue.tab` — every deep-link consumer (dashboard toast
        // in Task 2, future palette in 16-08) builds URLs via
        // route('dev.queue.tab', ['tab' => 'failed']) per the B-3 fix.
        Route::get('/queue', static fn () => redirect()->route('dev.queue.tab', ['tab' => 'pending']))
            ->name('dev.queue');
        Route::get('/queue/{tab}', QueueInspectorPage::class)
            ->where('tab', 'pending|failed|batches')
            ->name('dev.queue.tab');

        // 16-07: Doctor panel (CONTEXT D-43) + System snapshot
        // (CONTEXT D-44). Both pages live in the dev-shell layout and
        // are sourced from the `dev_mode_audit` table (doctor) +
        // ConfigFlattener (system).
        Route::get('/doctor', DoctorPanelPage::class)->name('dev.doctor');
        Route::get('/system', SystemSnapshotPage::class)->name('dev.system');
        // 16-07 Task 3: SELECT-only SQL panel + schema viewer
        // (CONTEXT D-45 / D-46 / D-47). Single page with inner sidebar
        // — I-6 LOCKED decision: NOT a separate /dev/sql/schema route.
        Route::get('/sql', SqlPanelPage::class)->name('dev.sql');

        // 16-06: Horizon iframe (D-38 two-signal). The route is ONLY
        // registered when BOTH `config('app.dev_mode') === true` AND
        // the Horizon package's HorizonServiceProvider class exists
        // (the package is `require-dev` per Phase 14 D-03, so it is
        // absent from a `--no-dev` shipped build). The dev-shell
        // sidebar reads `Route::has('dev.horizon')` to gate the nav
        // item — when either signal is false the route is absent and
        // the sidebar link drops off naturally.
        // Horizon iframe registration walks two constraints:
        //   - The `noHorizonImportsInShippedBuildCode` arch invariant
        //     forbids any non-stripped `Laravel\Horizon\` symbol
        //     outside `app/Providers/HorizonServiceProvider.php`. It
        //     strips `class_exists(\Laravel\Horizon\...)` arguments
        //     from the regex sweep first, so an inline FQCN inside
        //     `class_exists(...)` is legal — but the strip regex does
        //     NOT cover a top-of-file `use Laravel\Horizon\...` line.
        //   - Pint's `fully_qualified_strict_types` fixer would
        //     normally hoist an inline `Laravel\Horizon\…::class`
        //     into a top-of-file `use` line, breaking the arch test.
        //     The hoist is suppressed when an imported short name
        //     `HorizonServiceProvider` is already in scope — Pint
        //     refuses to introduce an ambiguity. The matching pattern
        //     lives in `bootstrap/providers.php` (Phase 14 D-04): the
        //     local `App\Providers\HorizonServiceProvider` is imported
        //     at the top of the file purely as a name-conflict shim
        //     AND used in the route registration body below, so the
        //     import is not unused.
        if (config('app.dev_mode') === true && class_exists(Laravel\Horizon\HorizonServiceProvider::class)) {
            // The presence of the local App\Providers\HorizonServiceProvider
            // is the second signal that Horizon is actually wired (its
            // boot() registers the dashboard routes the iframe targets).
            $horizonProviderClass = HorizonServiceProvider::class;
            if (class_exists($horizonProviderClass)) {
                Route::get('/horizon', HorizonFramePage::class)
                    ->middleware(HorizonFrameAncestors::class)
                    ->name('dev.horizon');
            }
        }
    });
