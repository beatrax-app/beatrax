<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Desktop\Internal\Http\CloseActionController;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Http\Livewire\FileStagingPage;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;

// Both first-launch routes sit outside the auth middleware group —
// they render before any user account exists. The welcome screen
// leads into /signup, itself open while no user exists yet.
Route::middleware(['web'])->group(static function (): void {
    Route::get('/setup', SetupScreen::class)->name('desktop.setup');
    Route::get('/welcome', WelcomeScreen::class)->name('desktop.welcome');
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    // NativePHP has no pre-close intercept facade (WindowClosed only
    // fires AFTER the close completes), so the Electron main process
    // navigates here on a first close (close_behavior IS NULL) and
    // POSTs directly to desktop.close-action on quit/tray.
    Route::get('/desktop/close-prompt', CloseWindowPrompt::class)
        ->name('desktop.close-prompt');

    // Once the OS-supplied file path is validated and routed via
    // FileOpenedFromOs, the user lands here bound to the pending
    // intent.
    Route::get('/desktop/file-staging', FileStagingPage::class)
        ->name('desktop.file-staging');

    // The CloseWindowPrompt component dispatches a browser event with
    // the chosen action; the in-layout JS hook POSTs the choice here.
    Route::post('/desktop/close-action', CloseActionController::class)
        ->name('desktop.close-action');
});
