<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Plan 03: PotsPage — the Livewire component class is registered once it exists.
// Until then, a closure stub is used so the route resolves without a fatal
// class-not-found at boot time. Mirrors the Goals pattern (STATE.md:
// "goals route registered as closure stub (returns 501) until Plan 04").

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/pots', static fn () => abort(501, 'Pots page not yet implemented — Plan 03.'))->name('pots.index');
});
