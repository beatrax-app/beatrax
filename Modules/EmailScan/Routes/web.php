<?php

declare(strict_types=1);

/*
 * EmailScan module routes.
 *
 * Wave 0 ships an empty middleware group so the service provider can
 * `loadRoutesFrom()` this file without error. The inboxes page, the
 * OAuth callback controller, and the per-row action endpoints land in
 * later plans.
 *
 * Route::facade is permitted in module Routes files (BoundaryArchTest
 * exempts `Modules\*\Routes`).
 */

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    // Routes land in later waves.
});
