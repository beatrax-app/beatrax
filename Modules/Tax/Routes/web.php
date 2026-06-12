<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    // Plan 05 will swap this closure stub for TaxPage::class once the
    // Livewire component exists — same pattern as goals.index.
    Route::get('/tax', static fn () => response('Not Implemented', 501))
        ->name('tax.index');
});
