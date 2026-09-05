<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Controllers\HealthController;
use Modules\Core\Public\Services\LocaleNegotiator;

// Auth-free liveness probe, registered outside the `web` middleware group so
// the `EnsureDatabaseReady` first-launch gate does not redirect pre-migration
// probes to the setup route. The response is a fixed four-key JSON object
// with no timestamp, so equality assertions remain stable across calls.
Route::get('/health', HealthController::class)->name('core.health');

// Guest-reachable language switch. SetLocale already reads
// `session('locale')` for unauthenticated requests; it just had no way to be
// set before sign-in, so the welcome, signup and login surfaces were
// English-only whatever the reader's language.

// A signed-in user's stored preference still outranks this session key, so
// switching here never overrides the Settings choice. Outside the `auth`
// group by design, and named so both first-launch gates exempt it — a 0-user
// device must be able to switch language on the welcome screen.
Route::post('/locale', static function (
    Request $request,
    UrlGenerator $urls,
    LocaleNegotiator $negotiator,
): RedirectResponse {
    $negotiator->rememberChoice($request->session(), $request->string('code')->toString());

    $back = $request->headers->get('referer');

    return new RedirectResponse(is_string($back) && $back !== '' ? $back : $urls->to('/'));
})->middleware(['web'])->name('locale.switch');

Route::middleware(['web', 'auth'])->group(static function (): void {
    // "Where is my data?" — the user-facing privacy page surfacing on-disk
    // paths via UserDataPathService, and the one-click export beside them.
    Route::view('/help/data-locations', 'core::help.data-locations')->name('core.help.data-locations');
});
