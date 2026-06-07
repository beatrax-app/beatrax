<?php

declare(strict_types=1);

namespace Modules\Goals\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Internal\Http\Livewire\GoalsSummaryCard;
use Modules\Goals\Public\Services\GoalProgressQuery;

/**
 * Wires the Goals module: migrations, routes, views, Livewire component
 * registrations, and the GoalProgressQuery singleton.
 *
 * `GoalProjectionService` and `GoalProgressQuery` autowire their constructor
 * deps (DatabaseManager / ExchangeRateService / ForecastQuery) — an explicit
 * singleton for GoalProgressQuery matches the Budgets pattern and prevents
 * multiple queries being built per request cycle.
 *
 * Plan 04 will add the two $livewire->component() registrations below once the
 * Livewire page and summary-card components exist.
 */
final class GoalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoalProgressQuery::class);
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

        $livewire->component('goals.goals-page', GoalsPage::class);
        $livewire->component('goals.summary-card', GoalsSummaryCard::class);
    }
}
