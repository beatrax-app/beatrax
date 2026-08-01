<?php

declare(strict_types=1);

namespace Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Ledger\Internal\Console\RederiveFingerprintsCommand;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Internal\Services\FingerprintRederiveService;
use Modules\Ledger\Public\Actions\ReassignCounterparty;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Actions\SetTransactionNote;
use Modules\Ledger\Public\Actions\UpdateTransactionCategory;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\StatementSummaryWriter;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;
use Modules\Ledger\Public\Services\TransactionListQuery;

// PeriodQuery is bound transient (not singleton) because it depends on
// the per-request CurrentUser contract; singleton scope would freeze
// the first-request user under a long-lived worker. The rederive-
// fingerprints command is registered behind a runningInConsole() guard.
final class LedgerServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->bind(RecordsTransactions::class, RecordTransactions::class);
        $this->app->bind(UpdatesTransactionCategory::class, UpdateTransactionCategory::class);
        $this->app->bind(RecordsStatementSummary::class, StatementSummaryWriter::class);
        $this->app->bind(SavesTransactionSplit::class, SaveTransactionSplit::class);
        // Counterparty-reassign and set/append-note are guarded Public
        // actions so every caller writes these fields through one seam,
        // never raw SQL.
        $this->app->bind(ReassignsCounterparty::class, ReassignCounterparty::class);
        $this->app->bind(SetsTransactionNote::class, SetTransactionNote::class);
        $this->app->singleton(FingerprintComposer::class);
        $this->app->bind(PeriodQuery::class);
        // Transient, not singleton — depends on the per-request
        // PeriodQuery binding above.
        $this->app->bind(CategorySpendTrendQuery::class);
        $this->app->singleton(ThisPeriodAtAGlanceQuery::class);
        $this->app->singleton(TopCategoriesByPeriodQuery::class);
        $this->app->singleton(TransactionListQuery::class);
        $this->app->bind(FingerprintRederiveService::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('ledger');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');

        $livewire->component('ledger.transactions-list', TransactionsList::class);
        $livewire->component('ledger.transaction-detail', TransactionDetail::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RederiveFingerprintsCommand::class,
            ]);
        }
    }
}
