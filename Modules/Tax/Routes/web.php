<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tax\Internal\Http\Livewire\TaxPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    // Plan 05: swapped from the 501 closure stub to the live TaxPage Livewire
    // component — same pattern as goals.index (GoalsPage::class) and
    // calendar.index (CalendarPage::class).
    Route::get('/tax', TaxPage::class)->name('tax.index');
});
