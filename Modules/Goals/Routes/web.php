<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/goals', GoalsPage::class)->name('goals.index');
});
