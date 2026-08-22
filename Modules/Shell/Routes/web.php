<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// The route names and URLs are the ones Core registered before the shell moved
// out of it: `dashboard` at / and `settings` at /settings. Both are linked from
// every module's views, so the names are a contract, not an implementation
// detail — only the module that serves them changed.
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
            return new RedirectResponse(Destination::Imports->urlFrom($urls));
        }

        return new Response($views->make('shell::dashboard')->render());
    })->name('dashboard');

    Route::view('/settings', 'shell::settings')->name('settings');
});
