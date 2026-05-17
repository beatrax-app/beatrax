<?php

declare(strict_types=1);

/*
 * Recurring module routes.
 *
 * `/recurring/review` — the dedicated review queue for pending,
 * rejected, and cadence-changed recurring suggestions. Renders the
 * `RecurringReviewPage` Livewire SFC behind `auth` + `web` middleware.
 * Cross-user isolation is enforced by the underlying Public Action
 * + RecurringSeriesQuery `where('user_id', ...)` scope on every
 * read and write.
 *
 * The `Route` facade is the documented carve-out for module Routes
 * files (`BoundaryArchTest` exempts `Modules\*\Routes`).
 */

use Illuminate\Support\Facades\Route;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/recurring/review', RecurringReviewPage::class)
        ->name('recurring.review');
});
