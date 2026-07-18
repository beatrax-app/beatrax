<?php

declare(strict_types=1);

/*
 * Notifications module routes.
 *
 * `/notifications` — the unified notification center surface (D-01, Req 2).
 * Named `notifications.index`, deliberately distinct from EmailScan's
 * email-connections route + its own route name — this module's routes,
 * component, and every user-facing string avoid that other module's
 * vocabulary entirely (D-01's zero-tolerance grep).
 *
 * Sits behind `auth` + `web` middleware, mirroring `Modules\DriftAlerts`'s
 * `/drift` registration. Cross-user isolation is enforced by the
 * underlying Public services + Actions (every read/write scopes by
 * `user_id`).
 *
 * The `Route` facade is permitted in module Routes files.
 */

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/notifications', NotificationsPage::class)->name('notifications.index');
});
