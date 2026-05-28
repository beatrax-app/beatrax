<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Counterparties module routes — the three authenticated surfaces:
 *
 *   - `/counterparties`         → cards-default index with type filter
 *   - `/counterparties/triage`  → focused single-card unknown queue
 *   - `/counterparties/{slug}`  → type-aware profile (5 type variants
 *                                 + unknown fallback)
 *
 * Routes target Blade wrapper views (`counterparties::index`,
 * `counterparties::profile`, `counterparties::triage`) that `@extends`
 * the project's `layouts.app` (which uses `@yield('content')` shape),
 * then inline the matching Livewire component. The Livewire `#[Layout]`
 * attribute on the components targets Livewire 4's `<x-layouts.app>`
 * Blade-component shape, which doesn't match the project's
 * `@extends`-style layout — going through a wrapper view sidesteps the
 * mismatch and keeps the layout single-source.
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
    Route::view('/counterparties', 'counterparties::index')
        ->name('counterparties.index');

    Route::view('/counterparties/triage', 'counterparties::triage')
        ->name('counterparties.triage');

    Route::get('/counterparties/{slug}', static fn (string $slug) => view('counterparties::profile', ['slug' => $slug]))
        ->name('counterparties.profile');
});
