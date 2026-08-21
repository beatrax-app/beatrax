<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tax\Internal\Http\Livewire\TaxPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/tax', TaxPage::class)->name('tax.index');
});
