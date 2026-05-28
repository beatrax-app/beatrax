<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

/*
 * Counterparties module routes — the three authenticated surfaces:
 *
 *   - `/counterparties`         → cards-default index with type filter
 *   - `/counterparties/triage`  → focused single-card unknown queue
 *   - `/counterparties/{slug}`  → type-aware profile (5 type variants
 *                                 + unknown fallback)
 *
 * Route order is load-bearing: the literal `/triage` MUST register
 * before the `/{slug}` placeholder so the Laravel router matches the
 * literal first. Reversing the order routes `/counterparties/triage`
 * into the profile page with `slug = "triage"` and yields a 404 for
 * users who happen to lack a counterparty slugged `triage` (every
 * user, in practice).
 *
 * Route names — `counterparties.index`, `counterparties.triage`,
 * `counterparties.profile` — are the symbolic targets sidebar
 * entries, in-app links, and Phase 17-06c cross-module surfaces
 * resolve against.
 */
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/counterparties', CounterpartyIndex::class)->name('counterparties.index');
    Route::get('/counterparties/triage', CounterpartyTriage::class)->name('counterparties.triage');
    Route::get('/counterparties/{slug}', CounterpartyProfile::class)->name('counterparties.profile');
});
