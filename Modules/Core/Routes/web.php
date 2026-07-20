<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Controllers\HealthController;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// Auth-free liveness probe, registered outside the `web` middleware group so
// the `EnsureDatabaseReady` first-launch gate does not redirect pre-migration
// probes to the setup route. The response is a fixed four-key JSON object
// with no timestamp, so equality assertions remain stable across calls.
Route::get('/health', HealthController::class)->name('core.health');

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
