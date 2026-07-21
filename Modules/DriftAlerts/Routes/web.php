<?php

declare(strict_types=1);

// Cross-user isolation is enforced by the underlying Public services and
// Actions (every read/write scopes by user_id). The Route facade is
// permitted in module Routes files.

use Illuminate\Support\Facades\Route;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Internal\Http\Livewire\SubscriptionDriftWatchPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/drift', DriftPage::class)->name('drift.index');
    Route::get('/drift/watch', SubscriptionDriftWatchPage::class)->name('drift.watch');
});
