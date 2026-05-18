<?php

declare(strict_types=1);

/*
 * Forecasting module routes.
 *
 * `/forecast` — the dedicated forecast surface with 30/60/90 horizon
 * switch, per-account chart, and scenario CRUD panel. Mounts the
 * `ForecastPage` Livewire SFC (delivered in a later wave; runtime
 * resolution happens at request time after that SFC lands).
 *
 * Sits behind `auth` + `web` middleware. Cross-user isolation is
 * enforced by the underlying Public services + Actions (every read /
 * write scopes by `user_id`).
 *
 * The `Route` facade is permitted in module Routes files.
 */

use Illuminate\Support\Facades\Route;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/forecast', ForecastPage::class)->name('forecast.index');
});
