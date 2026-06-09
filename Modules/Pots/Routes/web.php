<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pots\Internal\Http\Livewire\PotsPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/pots', PotsPage::class)->name('pots.index');
});
