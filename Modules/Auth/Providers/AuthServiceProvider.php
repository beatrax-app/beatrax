<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Internal\Console\ResetPasswordCommand;
use Modules\Auth\Internal\Fortify\FortifyServiceProvider;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Internal\Http\Middleware\ForcePasswordChangeMiddleware;
use Modules\Auth\Internal\Http\Middleware\ImpersonationBannerMiddleware;
use Modules\Auth\Internal\Http\Middleware\RequireDeveloperMiddleware;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Internal\Recovery\RecoveryCodeFormatter;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Internal\Recovery\RecoveryCodeNormalizer;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Auth\Public\Actions\EndImpersonationAction;
use Modules\Auth\Public\Actions\ImpersonateUserAction;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Auth\Public\Actions\ResetPasswordAction;
use Modules\Auth\Public\Actions\SignupAction;

/**
 * Service provider for the Auth module.
 *
 * Loads the module's migrations, web/console routes, and views, registers
 * the Fortify service provider that wires the username-based
 * authentication pipeline, binds the sign-in / sign-out / recovery-code /
 * password-reset / profile-switch actions, and registers the Livewire
 * pages of the authentication surface.
 *
 * Two middleware run on every authenticated route: the forced-password-
 * change guard and the profile-switch banner painter.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(FortifyServiceProvider::class);

        $this->app->singleton(LoginAction::class);
        $this->app->singleton(LogoutAction::class);
        $this->app->singleton(SignupAction::class);
        $this->app->singleton(AddUserAction::class);
        $this->app->singleton(ResetPasswordAction::class);
        $this->app->singleton(RegenerateRecoveryCodesAction::class);
        $this->app->singleton(ImpersonateUserAction::class);
        $this->app->singleton(EndImpersonationAction::class);
        $this->app->singleton(RecoveryCodeGenerator::class);
        $this->app->singleton(RecoveryCodeFormatter::class);
        $this->app->singleton(RecoveryCodeNormalizer::class);
        $this->app->singleton(RecoveryCodeAuthenticator::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire, Router $router): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'auth');

        if ($this->app->runningInConsole()) {
            $this->commands([ResetPasswordCommand::class]);
        }

        $router->aliasMiddleware('first-user-only', FirstUserOnlyMiddleware::class);
        $router->aliasMiddleware('developer', RequireDeveloperMiddleware::class);

        // Enforce the forced-password-change flag on every authenticated
        // route. The middleware exempts the change-password page and the
        // logout route by name so a flagged user is never trapped.
        $router->pushMiddlewareToGroup('auth', ForcePasswordChangeMiddleware::class);

        // Paint the profile-switch banner on every authenticated route
        // while a switch is active.
        $router->pushMiddlewareToGroup('auth', ImpersonationBannerMiddleware::class);

        // Defining an `auth` middleware group above shadows the framework's
        // `auth` middleware alias on every `->middleware('auth')` route.
        // Prepend the framework authentication middleware so the group still
        // rejects guests before the two module middleware run.
        $router->prependMiddlewareToGroup('auth', Authenticate::class);

        $livewire->component('auth.login-page', LoginPage::class);
        $livewire->component('auth.signup-page', SignupPage::class);
        $livewire->component('auth.recovery-codes-display', RecoveryCodesDisplay::class);
        $livewire->component('auth.change-password-page', ChangePasswordPage::class);
        $livewire->component('auth.add-user-page', AddUserPage::class);
        $livewire->component('auth.reset-password-page', ResetPasswordPage::class);
        $livewire->component('auth.manage-user-page', ManageUserPage::class);
    }
}
