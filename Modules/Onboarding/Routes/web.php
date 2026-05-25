<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;

/*
 * Onboarding module routes — the first-run setup wizard's surface.
 *
 * URL: `/setup-wizard` (avoids the URL collision with the Desktop
 * module's `/setup` migration splash, which already binds the literal
 * `/setup` URI to `desktop.setup`). The route NAME stays `setup` so
 * the post-signup redirect target + the Settings → First-run tour
 * link can call `route('setup')` symbolically.
 *
 * Auth-gated — the user is already signed up via the SignupAction
 * redirect chain when they reach this URL.
 *
 * Also EXEMPT from the EnsureDatabaseReady middleware's fresh-install
 * redirect via the `setup` route-NAME prefix in EXEMPT_ROUTE_PREFIXES
 * (see Modules/Desktop/.../EnsureDatabaseReady). The name-prefix
 * `setup` matches our `setup` route name; the gate would otherwise
 * bounce a freshly-signed-up user mid-wizard back to `/welcome`.
 */
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/setup-wizard', SetupWizard::class)->name('setup');
});
