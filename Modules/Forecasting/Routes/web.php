<?php

declare(strict_types=1);

// Cross-user isolation lives in the Public services and Actions (every read and
// write scopes by user_id), not in the routing layer.

use Illuminate\Support\Facades\Route;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/forecast', ForecastPage::class)->name('forecast.index');
});
