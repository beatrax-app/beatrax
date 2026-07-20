<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Sync\Internal\Http\Livewire\SyncHealthPage;

// The sync-health panel lives under /dev/* to place it in the Dev Console
// alongside the other system-health panels. It inherits the
// ensureDeveloperMode middleware alias — no developer access means no
// /dev/sync-health access.
Route::middleware(['web', 'auth', 'ensureDeveloperMode'])
    ->prefix('/dev')
    ->group(static function (): void {
        Route::get('/sync-health', SyncHealthPage::class)->name('dev.sync-health');
    });
