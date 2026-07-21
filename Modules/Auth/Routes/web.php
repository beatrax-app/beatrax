<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Internal\Http\Controllers\LockEngageController;
use Modules\Auth\Internal\Http\Controllers\WebAuthnBiometricController;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Public\Actions\LogoutAction;

Route::middleware(['web', 'guest'])->group(static function (): void {
    Route::get('/login', LoginPage::class)->name('login');

    Route::get('/reset-password', ResetPasswordPage::class)->name('auth.reset-password');

    Route::middleware([FirstUserOnlyMiddleware::class])->group(static function (): void {
        Route::get('/signup', SignupPage::class)->name('signup');
    });
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::post('/logout', static function (LogoutAction $logout, UrlGenerator $urls): RedirectResponse {
        $logout();

        return new RedirectResponse($urls->route('login'));
    })->name('logout');

    Route::get('/lock', LockScreen::class)->name('auth.lock');

    // Called by lock.js via fetch(keepalive:true) -- with the X-XSRF-TOKEN
    // CSRF header -- when the grace timer expires or the idle threshold is
    // met on an app page.
    Route::post('/lock/engage', LockEngageController::class)->name('auth.lock.engage');

    // lock.js POSTs here (throttled) when the user genuinely interacts.
    // The body is a no-op: simply passing through AppLockMiddleware
    // refreshes last_activity_at, since this is a plain (non-Livewire)
    // request -- Livewire/wire:poll traffic deliberately does not count.
    Route::post('/lock/activity', static fn (): Response => new Response('', 204))
        ->name('auth.lock.activity');

    Route::post('/lock/biometric/challenge', [WebAuthnBiometricController::class, 'challenge'])
        ->name('auth.lock.biometric.challenge');

    Route::post('/lock/biometric/verify', [WebAuthnBiometricController::class, 'verify'])
        ->name('auth.lock.biometric.verify');

    Route::post('/lock/biometric/enroll', [WebAuthnBiometricController::class, 'enroll'])
        ->name('auth.lock.biometric.enroll');

    Route::get('/recovery-codes', RecoveryCodesDisplay::class)->name('auth.recovery-codes-display');

    Route::get('/change-password', ChangePasswordPage::class)->name('auth.change-password');

    Route::middleware(['developer'])->group(static function (): void {
        Route::get('/settings/users/new', AddUserPage::class)->name('auth.users.create');
        Route::get('/settings/users/{username}', ManageUserPage::class)->name('auth.users.manage');
    });
});
