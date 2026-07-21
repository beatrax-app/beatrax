<?php

declare(strict_types=1);

// Cross-user isolation is enforced by the underlying Public services and
// Actions (every read/write scopes by user_id). The Route facade is the
// documented carve-out for module Routes files (BoundaryArchTest exempts
// Modules\*\Routes).

use Illuminate\Support\Facades\Route;
use Modules\Recurring\Internal\Http\Livewire\RecurringPage;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Internal\Http\Livewire\RecurringSeriesDetailPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/recurring', RecurringPage::class)
        ->name('recurring.index');
    Route::get('/recurring/review', RecurringReviewPage::class)
        ->name('recurring.review');
    Route::get('/recurring/series/{seriesId}', RecurringSeriesDetailPage::class)
        ->whereNumber('seriesId')
        ->name('recurring.series.show');
});
