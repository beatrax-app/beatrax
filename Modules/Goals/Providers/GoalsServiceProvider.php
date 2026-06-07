<?php

declare(strict_types=1);

namespace Modules\Goals\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Wires the Goals module: migrations, routes, views, and Livewire component
 * registrations.
 *
 * `register()` is intentionally empty in Plan 01.
 * The `GoalProgressQuery` singleton is registered in Plan 02 (avoids
 * referencing a class that does not yet exist).
 * `GoalWriter` (Plan 03) autowires with ONLY a `DatabaseManager` dependency
 * and does NOT depend on `GoalProgressQuery` in its constructor.
 *
 * Plan 04 will add the two $livewire->component() registrations below once the
 * Livewire page and summary-card components exist.
 */
final class GoalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Intentionally empty — see docblock above.
        // Plan 02 will add: $this->app->singleton(GoalProgressQuery::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'goals');
        }

        // TODO (Plan 04): register Livewire components once they exist.
        // $livewire->component('goals.goals-page', GoalsPage::class);
        // $livewire->component('goals.summary-card', GoalsSummaryCard::class);
    }
}
