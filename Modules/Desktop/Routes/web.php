<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Http\Livewire\SetupScreen;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;

/*
 * Plan 15-05 first-launch routes (D-21 / D-22).
 *
 * Both routes deliberately sit OUTSIDE the `auth` middleware group —
 * they render BEFORE any user account exists. The welcome screen leads
 * into `/signup`, which is itself open while `User::count() === 0`
 * (Phase 12 D-03 / `FirstUserOnlyMiddleware`).
 *
 * The setup route is intentionally listed BEFORE the `EnsureDatabaseReady`
 * gate so a future `Route::middleware(...)` enclosure does not redirect-loop
 * the setup screen back onto itself; the gate middleware itself also keeps
 * the setup route name on an exempt list as a second layer of safety.
 */
Route::middleware(['web'])->group(static function (): void {
    Route::get('/setup', SetupScreen::class)->name('desktop.setup');
    Route::get('/welcome', WelcomeScreen::class)->name('desktop.welcome');
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    // D-08 first-close prompt — Quit vs Keep-in-tray. The route exists
    // so the NativePHP close-intercept hook can navigate the focused
    // window here on a first close (when users.close_behavior IS NULL);
    // the in-bundle hook lives in NativeAppServiceProvider and decides
    // whether to navigate-and-prompt (NULL) or apply the recorded
    // choice directly (non-NULL) without ever surfacing this route to
    // the user.
    Route::get('/desktop/close-prompt', CloseWindowPrompt::class)
        ->name('desktop.close-prompt');
});
