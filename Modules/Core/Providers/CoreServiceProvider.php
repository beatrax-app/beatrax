<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Loads Core module migrations, routes, and views. Public/Contracts bindings
 * land here in later plans.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 02 binds Core\Public\Contracts\CurrentUser and Clock here.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'core');
    }
}
