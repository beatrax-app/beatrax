<?php

declare(strict_types=1);

/*
 * Drift Alerts module routes.
 *
 * Wave 0 ships the `web` + `auth` middleware envelope so the service
 * provider can `loadRoutesFrom()` this file without error. The
 * `/drift` Livewire route mapped to `DriftPage` lands in a later
 * wave (the Livewire SFC is delivered then); registering it here
 * before the handler class exists would cause `php artisan` to fail
 * route registration. Once the handler class is in place the line
 *
 *     Route::get('/drift', DriftPage::class)->name('drift.index');
 *
 * activates the route inside this same middleware group.
 *
 * Sits behind `auth` + `web` middleware. Cross-user isolation is
 * enforced by the underlying Public services + Actions (every read /
 * write scopes by `user_id`).
 *
 * The `Route` facade is the documented carve-out for module Routes
 * files (`BoundaryArchTest` exempts `Modules\*\Routes`).
 */

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    // The `/drift` route lands in a later wave once the DriftPage
    // Livewire SFC class exists.
});
