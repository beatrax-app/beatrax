<?php

declare(strict_types=1);

// `/forecast` sits behind web + auth middleware. Cross-user isolation is
// enforced by the underlying Public services + Actions (every read/write
// scopes by user_id). The Route facade is permitted in module Routes files.

use Illuminate\Support\Facades\Route;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/forecast', ForecastPage::class)->name('forecast.index');
});
