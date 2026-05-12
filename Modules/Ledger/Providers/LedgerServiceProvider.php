<?php

declare(strict_types=1);

namespace Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Actions\UpdateTransactionCategory;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;
use Modules\Ledger\Public\Services\TransactionListQuery;

/**
 * Wires the Ledger module:
 *
 * - binds the two public action contracts so other modules can inject them
 * - registers FingerprintComposer as a singleton (stateless, cheap)
 * - registers PeriodQuery as a transient binding because it depends on the
 *   per-request CurrentUser contract; singleton scope would freeze the
 *   first-request user under a long-lived worker
 * - registers the three dashboard query services as singletons (stateless,
 *   user is supplied per call)
 * - loads migrations, routes, and views
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecordsTransactions::class, RecordTransactions::class);
        $this->app->bind(UpdatesTransactionCategory::class, UpdateTransactionCategory::class);
        $this->app->singleton(FingerprintComposer::class);
        $this->app->bind(PeriodQuery::class);
        $this->app->singleton(ThisPeriodAtAGlanceQuery::class);
        $this->app->singleton(TopCategoriesByPeriodQuery::class);
        $this->app->singleton(TransactionListQuery::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ledger');

        $livewire->component('ledger.transactions-list', TransactionsList::class);
    }
}
