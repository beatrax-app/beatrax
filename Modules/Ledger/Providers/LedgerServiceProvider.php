<?php

declare(strict_types=1);

namespace Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Actions\UpdateTransactionCategory;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * Wires the Ledger module:
 *
 * - binds the two public action contracts so other modules can inject them
 * - registers FingerprintComposer + PeriodQuery as singletons (cheap, stateless)
 * - loads migrations, routes, and views
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecordsTransactions::class, RecordTransactions::class);
        $this->app->bind(UpdatesTransactionCategory::class, UpdateTransactionCategory::class);
        $this->app->singleton(FingerprintComposer::class);
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
