<?php

declare(strict_types=1);

/*
 * EmailScan module routes.
 *
 * Wave 2 wires the per-inbox OAuth dance:
 *  - GET /inboxes               — top-nav landing page (Livewire SFC)
 *  - GET /oauth/connect/{provider} — kicks off OAuth consent (issues state, redirects)
 *  - GET /oauth/callback/{provider} — handles the IdP redirect (verifies state, exchanges code)
 *
 * The two OAuth routes carry the bare 'web' middleware so the session
 * cookie + state-CSRF round-trip work; the 'auth' middleware ensures
 * the callback is bound to the same authenticated user that initiated
 * the connect step.
 *
 * Route::facade is permitted in module Routes files (BoundaryArchTest
 * exempts Modules\*\Routes).
 */

use Illuminate\Support\Facades\Route;
use Modules\EmailScan\Internal\Http\Controllers\OAuthCallbackController;
use Modules\EmailScan\Internal\Http\Controllers\OAuthConnectController;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/inboxes', InboxesPage::class)->name('inboxes.index');
    Route::get('/oauth/connect/{provider}', OAuthConnectController::class)->name('oauth.connect');
    Route::get('/oauth/callback/{provider}', OAuthCallbackController::class)->name('oauth.callback');
});
