<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;

// The URI is /setup-wizard because Desktop's migration splash owns /setup, but
// the route NAME stays "setup" so EnsureDatabaseReady's name-prefix exemption
// still matches and doesn't bounce a mid-wizard user back to /welcome.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/setup-wizard', SetupWizard::class)->name('setup');
});
