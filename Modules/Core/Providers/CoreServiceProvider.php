<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Internal\Providers\SqliteOptimizationsProvider;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemClock;

/**
 * Loads Core module migrations, routes, and views, and binds the module's
 * public contracts (`Clock`) and connection-level SQLite pragma listener.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(SqliteOptimizationsProvider::class);
        $this->app->singleton(Clock::class, SystemClock::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'core');
    }
}
