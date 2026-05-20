<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Public\Actions\LogoutAction;

Route::middleware(['web', 'guest'])->group(static function (): void {
    Route::get('/login', LoginPage::class)->name('login');

    Route::middleware([FirstUserOnlyMiddleware::class])->group(static function (): void {
        Route::get('/signup', SignupPage::class)->name('signup');
    });
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::post('/logout', static function (LogoutAction $logout, UrlGenerator $urls): RedirectResponse {
        $logout();

        return new RedirectResponse($urls->route('login'));
    })->name('logout');

    Route::get('/recovery-codes', RecoveryCodesDisplay::class)->name('auth.recovery-codes-display');
});
