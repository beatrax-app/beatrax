<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
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
    });
