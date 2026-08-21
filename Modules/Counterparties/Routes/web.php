<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Order is load-bearing: /counterparties/triage must register before the
// /{slug} placeholder or the placeholder swallows it.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/counterparties', 'counterparties::index')
        ->name('counterparties.index');

    Route::view('/counterparties/triage', 'counterparties::triage')
        ->name('counterparties.triage');

    Route::get('/counterparties/{slug}', static fn (string $slug) => view('counterparties::profile', ['slug' => $slug]))
        ->name('counterparties.profile');
});
