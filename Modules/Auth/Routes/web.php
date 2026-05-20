<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Public\Actions\EndImpersonationAction;
use Modules\Auth\Public\Actions\ImpersonateUserAction;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Auth\Public\Dto\ImpersonationResult;

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

    Route::get('/recovery-codes', RecoveryCodesDisplay::class)->name('auth.recovery-codes-display');

    Route::get('/change-password', ChangePasswordPage::class)->name('auth.change-password');

    // Ending a profile switch needs only `auth` — whoever is currently
    // impersonating must always be able to return to themselves.
    Route::post('/impersonate/end', static function (
        EndImpersonationAction $end,
        UrlGenerator $urls,
    ): RedirectResponse {
        $end();

        return new RedirectResponse($urls->route('dashboard'));
    })->name('auth.impersonate.end');

    Route::middleware(['developer'])->group(static function (): void {
        Route::get('/settings/users/new', AddUserPage::class)->name('auth.users.create');
        Route::get('/settings/users/{username}', ManageUserPage::class)->name('auth.users.manage');

        // Starting a profile switch is developer-gated: a non-developer
        // never sees this route (404), and the action re-checks the
        // developer flag plus the caller's password as defence in depth.
        Route::post('/impersonate', static function (
            Request $request,
            ImpersonateUserAction $impersonate,
            UrlGenerator $urls,
        ): RedirectResponse {
            $password = $request->input('password');
            $targetUserId = $request->input('target_user_id');

            $result = $impersonate(
                is_string($password) ? $password : '',
                is_numeric($targetUserId) ? (int) $targetUserId : 0,
            );

            if (! $result->isSuccess()) {
                $message = match ($result->status) {
                    ImpersonationResult::STATUS_WRONG_PASSWORD => 'Wrong password. Try again, or cancel to stay as yourself.',
                    ImpersonationResult::STATUS_INVALID_TARGET => 'That user cannot be acted as.',
                    default => 'Acting as another user is not available.',
                };

                return (new RedirectResponse($urls->previous()))
                    ->withErrors(['impersonate' => $message]);
            }

            return new RedirectResponse($urls->route('dashboard'));
        })->name('auth.impersonate');
    });
});
