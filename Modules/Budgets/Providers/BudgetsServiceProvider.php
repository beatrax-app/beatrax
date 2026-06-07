<?php

declare(strict_types=1);

namespace Modules\Budgets\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\BudgetProgressQuery;

/**
 * Wires the Budgets module: the read-model query as a singleton, and the
 * conditional load of migrations / routes / views plus the BudgetsPage
 * Livewire component.
 */
final class BudgetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BudgetProgressQuery::class);
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
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'budgets');
        }

        $livewire->component('budgets.budgets-page', BudgetsPage::class);
    }
}
