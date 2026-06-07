<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    // GoalsPage is created in Plan 04; the route name is reserved here so
    // Plans 02, 03, and 04 can reference route('goals.index') without ordering
    // concerns.
    Route::get('/goals', GoalsPage::class)->name('goals.index');
});
