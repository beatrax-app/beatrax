<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DevMode\Internal\Http\Controllers\AdvancedToggleController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanCancelController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanSpawnController;
use Modules\DevMode\Internal\Http\Controllers\ArtisanStreamController;
use Modules\DevMode\Internal\Http\Controllers\DestructiveSpawnController;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Http\Livewire\AuditLogPage;
use Modules\DevMode\Internal\Http\Livewire\DevOverviewPage;

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
    });
