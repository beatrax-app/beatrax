<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Desktop\Internal\Http\CloseActionController;
use Modules\Desktop\Internal\Http\FileOpenController;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Http\Livewire\FileStagingPage;
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

    /*
     * File-open intake (PKG-06 / D-04). The Electron main process POSTs
     * here when it sees a `.csv` / `.eml` file path from argv or a
     * second-instance launch. Behind `['web']` only — a logged-out
     * file-open must still be accepted so PendingFileIntent can save
     * it across the login round-trip.
     */
    Route::post('/desktop/file-open', FileOpenController::class)
        ->name('desktop.file-open');
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    // D-08 first-close prompt — Quit vs Keep-in-tray. The route exists
    // so the NativePHP-bundle close-intercept hook (Electron-side, in
    // the published `nativephp/electron/src/main/index.js`) can navigate
    // the focused window here on a first close — when
    // `users.close_behavior IS NULL`. NativePHP/PHP has no pre-close
    // intercept facade (the only `WindowClosed` PHP event fires AFTER
    // the close completes), so the navigation trigger lives in the
    // Electron main process: it reads the user's close_behavior via the
    // bundled HTTP endpoint, navigates to this route on NULL, and POSTs
    // directly to `desktop.close-action` on quit/tray.
    //
    // On arrival, `CloseWindowPrompt::mount()` dispatches `modal-show`
    // so the flux:modal surfaces immediately — without that dispatch
    // the page would render an invisible modal element.
    Route::get('/desktop/close-prompt', CloseWindowPrompt::class)
        ->name('desktop.close-prompt');

    /*
     * The .csv / .eml staging page (D-01 / D-02). Once the OS-supplied
     * file path is validated and routed via the FileOpenedFromOs Public
     * event, the user lands here. The page reads the current pending
     * intent from PendingFileIntent and renders the "File received:
     * <name>" + "Start import" CTA, or the "We couldn't open that
     * file" empty state when no intent is present.
     */
    Route::get('/desktop/file-staging', FileStagingPage::class)
        ->name('desktop.file-staging');

    /*
     * D-08 close-action endpoint. The CloseWindowPrompt Livewire
     * component dispatches a browser event with the user's chosen
     * action; the in-layout JS hook POSTs the choice here. The
     * controller's allow-list guard re-validates the value before
     * calling App::quit() / Window::current()->hide() inside
     * ApplyCloseWindowChoice. JS glue deferred from plan 15-03.
     */
    Route::post('/desktop/close-action', CloseActionController::class)
        ->name('desktop.close-action');
});
