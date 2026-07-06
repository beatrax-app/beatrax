<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Wave 0 stub (this plan). No routes yet — Plan 08 fills this group with
 * /migrations, /migrations/new, /migrations/{run}/preview, and
 * /migrations/{run}/results per the UI-SPEC. An empty middleware group must
 * not error at boot; this file exists purely so
 * MigrationServiceProvider::boot()'s `loadRoutesFrom` guard has something
 * to load and route:list / config:clear succeed with zero registered routes.
 */
Route::middleware(['web', 'auth'])->group(static function (): void {
    // Plan 08 injection point: real routes land here.
});
