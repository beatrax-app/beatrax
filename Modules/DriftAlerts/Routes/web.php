<?php

declare(strict_types=1);

/*
 * Drift Alerts module routes.
 *
 * `/drift` — the dedicated drift-alerts surface with Open / History /
 * Dismissed tabs. Mounts the `DriftPage` Livewire SFC.
 *
 * Sits behind `auth` + `web` middleware. Cross-user isolation is
 * enforced by the underlying Public services + Actions (every read /
 * write scopes by `user_id`).
 *
 * The `Route` facade is the documented carve-out for module Routes
 * files (`BoundaryArchTest` exempts `Modules\*\Routes`).
 */

use Illuminate\Support\Facades\Route;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/drift', DriftPage::class)->name('drift.index');
});
