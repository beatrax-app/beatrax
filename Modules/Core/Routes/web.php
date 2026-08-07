<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Controllers\HealthController;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

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
): RedirectResponse {
    $code = $request->string('code')->toString();

    if (Locale::isSupported($code)) {
        $request->session()->put('locale', $code);
    }

    $back = $request->headers->get('referer');

    return new RedirectResponse(is_string($back) && $back !== '' ? $back : $urls->to('/'));
})->middleware(['web'])->name('locale.switch');

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/', static function (
        CurrentUser $currentUser,
        PeriodQuery $periods,
        ThisPeriodAtAGlanceQuery $glance,
        UrlGenerator $urls,
        ViewFactory $views,
    ): RedirectResponse|Response {
        // First-run redirect: zero transactions → /imports/new. Keeps the
        // dashboard from rendering empty tiles on a fresh install.
        $summary = $glance->for($currentUser->user(), $periods->current());
        if ($summary->isFirstRun) {
            return new RedirectResponse($urls->route('imports.new'));
        }

        return new Response($views->make('core::dashboard')->render());
    })->name('dashboard');

    Route::view('/settings', 'core::settings')->name('settings');

    // "Where is my data?" — the user-facing privacy page surfacing on-disk
    // paths via UserDataPathService, gated on is_developer for the export-
    // everything CTA. The wrapper Blade mirrors the dashboard view's
    // @extends + @section + @livewire wiring shape.
    Route::view('/help/data-locations', 'core::help.data-locations')->name('core.help.data-locations');
});
