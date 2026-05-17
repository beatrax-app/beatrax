<?php

declare(strict_types=1);

namespace Modules\Recurring\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;

/**
 * Wires the Recurring module.
 *
 * Boot conditionally loads the module's migrations, routes, and views
 * when each directory is present so the empty skeleton boots cleanly
 * without forcing any of those subtrees to exist yet. register() binds
 * the in-tree services as singletons; detectors, queries, and actions
 * attach in future waves alongside their implementations.
 */
final class RecurringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecurringSeriesStateMachine::class);
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
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'recurring');
        }
    }
}
