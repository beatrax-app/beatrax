<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DevMode\Internal\Http\Controllers\AdvancedToggleController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanCancelController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanSpawnController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanStreamController;
use Modules\DevMode\Internal\Http\Controllers\DestructiveSpawnController;
use Modules\DevMode\Internal\Http\Controllers\LogStreamController;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Internal\Http\Livewire\DevOverviewPage;
use Modules\DevMode\Internal\Http\Livewire\DoctorPanelPage;
use Modules\DevMode\Internal\Http\Livewire\LogTailerPage;
use Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage;
use Modules\DevMode\Internal\Http\Livewire\SqlPanelPage;
use Modules\DevMode\Internal\Http\Livewire\SystemSnapshotPage;

/*
 * Dev Console routes. Mounted by DevModeServiceProvider via
 * loadRoutesFrom(__DIR__.'/../Routes/web.php').
 *
 * Every /dev/* route applies the `ensureDeveloperMode` middleware
 * alias registered in DevModeServiceProvider::boot(). The arch
 * invariant `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`
 * (in tests/Contracts/BoundaryArchTest.php) locks the coverage at
 * PR time: a route that lacks the alias fails CI.
 *
 * The dev-shell layout's Router::has(...) check drops the
 * `nav-disabled` class from the matching sidebar entry once each
 * route is registered.
 */
Route::middleware(['web', 'auth', 'ensureDeveloperMode'])
    ->prefix('/dev')
    ->group(static function (): void {
        Route::get('/', DevOverviewPage::class)->name('dev.overview');

        // Artisan runner page + Audit log page.
        Route::get('/artisan', ArtisanRunnerPage::class)->name('dev.artisan');
        Route::get('/audit', AuditLogPage::class)->name('dev.audit');

        // SAFE-tier spawn pipeline: JSON spawn endpoint, SSE stream,
        // cancel endpoint. The DESTRUCTIVE pipeline lives on its own
        // controller below.
        Route::post('/artisan/spawn', ArtisanSpawnController::class)
            ->name('dev.artisan.spawn');
        Route::get('/artisan/stream/{runId}', ArtisanStreamController::class)
            ->name('dev.artisan.stream');
        Route::post('/artisan/cancel/{runId}', ArtisanCancelController::class)
            ->name('dev.artisan.cancel');

        Route::post('/advanced-toggle', AdvancedToggleController::class)
            ->name('dev.advanced-toggle');

        // DESTRUCTIVE-tier spawn endpoint. SEPARATE from the
        // SAFE-tier ArtisanSpawnController above; this controller
        // RE-VALIDATES the triple-gate (env + session + typed)
        // before routing through CommandSpawner::start(...,
        // 'destructive').
        Route::post('/artisan/destructive-spawn', DestructiveSpawnController::class)
            ->name('dev.artisan.destructive-spawn');

        // Log tailer page + JSON poll + ±10-line context lookup. The
        // page mounts the Alpine ring-buffer + 1 Hz fetch poller; the
        // poll controller re-applies RedactSecretsProcessor as
        // defense-in-depth on top of the on-write Monolog tap. Each
        // poll returns immediately — the earlier SSE version held the
        // single-threaded PHP built-in server's only worker, stalling
        // every other in-app navigation.
        Route::get('/logs', LogTailerPage::class)->name('dev.logs');
        Route::get('/logs/poll', [LogStreamController::class, 'poll'])->name('dev.logs.poll');
        Route::get('/logs/context', [LogStreamController::class, 'context'])->name('dev.logs.context');

        // Queue inspector. Three sub-routes back the same Livewire
        // component; the bare /dev/queue URL redirects to the
        // default `pending` tab so deep-link ergonomics stay intact.
        // The canonical route name is `dev.queue.tab` — every
        // deep-link consumer (dashboard toast, command palette)
        // builds URLs via
        // route('dev.queue.tab', ['tab' => 'failed']).
        Route::get('/queue', static fn () => redirect()->route('dev.queue.tab', ['tab' => 'pending']))
            ->name('dev.queue');
        Route::get('/queue/{tab}', QueueInspectorPage::class)
            ->where('tab', 'pending|failed|batches')
            ->name('dev.queue.tab');

        // Doctor panel + System snapshot. Both pages live in the
        // dev-shell layout and are sourced from the dev_mode_audit
        // table (doctor) + ConfigFlattener (system).
        Route::get('/doctor', DoctorPanelPage::class)->name('dev.doctor');
        Route::get('/system', SystemSnapshotPage::class)->name('dev.system');
        // SELECT-only SQL panel + schema viewer. Single page with
        // an inner sidebar — schema viewer is NOT a separate
        // /dev/sql/schema route.
        Route::get('/sql', SqlPanelPage::class)->name('dev.sql');

        // The Horizon iframe route is conditionally registered by the
        // ServiceProvider's boot() method — DI-only access to the
        // Config\Repository lives there, where the dev-mode flag and
        // Horizon-package signals are read through injected
        // collaborators rather than the config() global helper.
    });
