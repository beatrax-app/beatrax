<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Sync\Internal\Http\Livewire\SyncHealthPage;

/*
 * Sync module web routes.
 *
 * The sync-health panel lives under /dev/* to place it in the Dev
 * Console alongside the other system-health panels (Doctor, SQL,
 * System). It inherits the `ensureDeveloperMode` middleware alias
 * registered in DevModeServiceProvider::boot() — no developer access
 * means no /dev/sync-health access.
 *
 * This route file is loaded by SyncServiceProvider via
 * loadRoutesFrom(__DIR__.'/../Routes/web.php').
 */
Route::middleware(['web', 'auth', 'ensureDeveloperMode'])
    ->prefix('/dev')
    ->group(static function (): void {
        Route::get('/sync-health', SyncHealthPage::class)->name('dev.sync-health');
    });
