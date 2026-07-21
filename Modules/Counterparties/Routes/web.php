<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Route order is load-bearing: /counterparties/triage MUST register
// before the /{slug} placeholder so the router matches the literal
// first (see the linked architecture doc for the full rationale).
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/counterparties', 'counterparties::index')
        ->name('counterparties.index');

    Route::view('/counterparties/triage', 'counterparties::triage')
        ->name('counterparties.triage');

    Route::get('/counterparties/{slug}', static fn (string $slug) => view('counterparties::profile', ['slug' => $slug]))
        ->name('counterparties.profile');
});
