<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Search module web routes.
 *
 * The palette search endpoint operates via Livewire's $wire.search(q)
 * mechanism (the mounted component receives the call directly — no
 * HTTP route is needed for the palette JS fetch path).
 *
 * This file is loaded by SearchServiceProvider::boot() when it exists
 * (guarded by is_file()). Auth-gated route group reserved for future
 * search HTTP endpoints (e.g. a dedicated /search page that renders
 * TransactionsList in search mode).
 */
Route::middleware(['web', 'auth'])->group(static function (): void {
    // Future: Route::view('/search', 'search::search')->name('search.index');
});
