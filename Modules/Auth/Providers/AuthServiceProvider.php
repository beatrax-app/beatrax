<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Internal\Fortify\FortifyServiceProvider;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Internal\Recovery\RecoveryCodeFormatter;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Auth\Public\Actions\SignupAction;

/**
 * Service provider for the Auth module.
 *
 * Loads the module's migrations, web/console routes, and views, registers
 * the Fortify service provider that wires the username-based
 * authentication pipeline, binds the sign-in / sign-out actions, and
 * registers the Livewire pages of the authentication surface. Further
 * bindings — recovery-code, password-reset, and impersonation actions —
 * are registered by the relevant plan as those surfaces are built out.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(FortifyServiceProvider::class);

        $this->app->singleton(LoginAction::class);
        $this->app->singleton(LogoutAction::class);
        $this->app->singleton(SignupAction::class);
        $this->app->singleton(RecoveryCodeGenerator::class);
        $this->app->singleton(RecoveryCodeFormatter::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire, Router $router): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'auth');

        $router->aliasMiddleware('first-user-only', FirstUserOnlyMiddleware::class);

        $livewire->component('auth.login-page', LoginPage::class);
        $livewire->component('auth.signup-page', SignupPage::class);
        $livewire->component('auth.recovery-codes-display', RecoveryCodesDisplay::class);
    }
}
