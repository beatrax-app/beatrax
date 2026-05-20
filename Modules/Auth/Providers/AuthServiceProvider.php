<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Internal\Fortify\FortifyServiceProvider;

/**
 * Service provider for the Auth module.
 *
 * Loads the module's migrations, web/console routes, and views, and
 * registers the Fortify service provider that wires the username-based
 * authentication pipeline. Concrete bindings — actions, services, and
 * Livewire components — are registered by the relevant plan as the
 * authentication, recovery-code, password-reset, and impersonation
 * surfaces are built out.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(FortifyServiceProvider::class);

        // Further bindings (Public actions, Internal services, Livewire
        // components) are added as the authentication surfaces are
        // implemented.
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'auth');
    }
}
