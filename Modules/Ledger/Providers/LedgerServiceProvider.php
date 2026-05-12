<?php

declare(strict_types=1);

namespace Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * Wires the Ledger module: loads migrations + routes + views, and registers
 * Public service singletons. The RecordsTransactions / UpdatesTransactionCategory
 * action bindings are added once those actions land in the next commit.
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PeriodQuery::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ledger');
    }
}
