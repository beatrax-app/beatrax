<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The palette search endpoint operates via Livewire's $wire.search(q)
// mechanism, so no HTTP route is needed yet. This group is reserved for
// a future dedicated /search page rendering TransactionsList in search
// mode.
Route::middleware(['web', 'auth'])->group(static function (): void {
    // Intentionally empty until that /search page exists; the middleware
    // stack is declared now so the future route inherits web + auth.
});
