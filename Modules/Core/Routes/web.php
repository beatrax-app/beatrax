<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/', static function (
        CurrentUser $currentUser,
        PeriodQuery $periods,
        ThisPeriodAtAGlanceQuery $glance,
        UrlGenerator $urls,
        ViewFactory $views,
    ): RedirectResponse|Response {
        // D-18 first-run redirect: zero transactions → /imports/new.
        $summary = $glance->for($currentUser->user(), $periods->current());
        if ($summary->isFirstRun) {
            return new RedirectResponse($urls->route('imports.new'));
        }

        return new Response($views->make('core::dashboard')->render());
    })->name('dashboard');
});
