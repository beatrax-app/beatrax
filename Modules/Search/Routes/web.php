<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The palette search endpoint operates via Livewire's $wire.search(q)
// mechanism — no HTTP route needed. This group is reserved for a
// future dedicated /search page rendering TransactionsList in search
// mode.
Route::middleware(['web', 'auth'])->group(static function (): void {});
