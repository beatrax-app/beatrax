<?php

declare(strict_types=1);

/*
 * Chains module routes.
 *
 * `/chains/review` — Wave 4 review queue (CHN-03 / D-86). Renders the
 * `ChainReviewQueue` Livewire SFC behind `auth` + `web` middleware
 * (Phase 1 Fortify-auth convention). Cross-user isolation is enforced
 * by the underlying ChainLinkQuery + Confirm/Reject Public actions
 * (firstOrFail() on user_id).
 *
 * Route::facade is permitted in module Routes files (BoundaryArchTest
 * exempts `Modules\*\Routes`).
 */

use Illuminate\Support\Facades\Route;
use Modules\Chains\Internal\Http\Livewire\ChainHintsQueue;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/chains/review', ChainReviewQueue::class)
        ->name('chains.review');
    Route::get('/chains/hints', ChainHintsQueue::class)
        ->name('chains.hints');
});
