<?php

declare(strict_types=1);

namespace Modules\Goals\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Internal\Http\Livewire\GoalsSummaryCard;
use Modules\Goals\Public\Services\GoalProgressQuery;

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
