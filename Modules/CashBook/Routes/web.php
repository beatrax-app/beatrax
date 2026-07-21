<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/cash', CashBookPage::class)->name('cashbook.index');
});
