<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;

// URL is /setup-wizard (Desktop's /setup migration splash already owns
// the literal /setup URI), but the route NAME stays "setup" so
// EnsureDatabaseReady's route-name-prefix exemption still matches and
// doesn't bounce a mid-wizard user back to /welcome.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/setup-wizard', SetupWizard::class)->name('setup');
});
