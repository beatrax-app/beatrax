<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// GoalsPage is created in Plan 04. The route name 'goals.index' is registered
// here as a stub that returns a 501 so Plans 02 and 03 can reference the name
// without a fatal. Plan 04 will swap this closure for GoalsPage::class.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/goals', static function (): never {
        abort(501, 'Goals page not yet implemented — see Plan 04.');
    })->name('goals.index');
});
