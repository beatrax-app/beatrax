<?php

declare(strict_types=1);

// Cross-user isolation for these routes is enforced by the underlying
// ChainLinkQuery + Confirm/Reject Public actions (firstOrFail on
// user_id), not by the routing layer. Route::facade is permitted in
// module Routes files (BoundaryArchTest exempts Modules\*\Routes).

use Illuminate\Support\Facades\Route;
use Modules\Chains\Internal\Http\Livewire\ChainHintsQueue;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Internal\Http\Livewire\ChainsIndex;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/chains', ChainsIndex::class)
        ->name('chains.index');
    Route::get('/chains/review', ChainReviewQueue::class)
        ->name('chains.review');
    Route::get('/chains/hints', ChainHintsQueue::class)
        ->name('chains.hints');
});
