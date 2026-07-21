<?php

declare(strict_types=1);

namespace Modules\Position\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Position\Public\Services\PositionQuery;

/**
 * @link ../../../.docs/features/position/architecture.md
 */
final class PositionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PositionQuery::class);
    }

    public function boot(): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'position');
        }
    }
}
