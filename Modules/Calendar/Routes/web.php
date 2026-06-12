<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/calendar', CalendarPage::class)->name('calendar.index');
});
