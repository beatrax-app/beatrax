<?php

declare(strict_types=1);

namespace Modules\Ledger\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Auth\Public\Events\AppLockUnlocked;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Ledger\Internal\Console\RederiveFingerprintsCommand;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Internal\Listeners\SweepAsnDelimitersOnUnlock;
use Modules\Ledger\Internal\Services\CounterpartyKeyProvenance;
use Modules\Ledger\Internal\Services\FingerprintRederiveService;
use Modules\Ledger\Internal\Sync\NullTransactionSyncCapture;
use Modules\Ledger\Public\Actions\ReassignCounterparty;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Actions\SetTransactionNote;
use Modules\Ledger\Public\Actions\UpdateTransactionCategory;
use Modules\Ledger\Public\Contracts\CapturesTransactionsForSync;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\StatementSummaryWriter;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;
use Modules\Ledger\Public\Services\TransactionListQuery;
use Modules\Sync\Public\Contracts\BlindIndexProvenance;

final class LedgerServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        // Sync overrides this when loaded; the null capture keeps the
        // transaction writers resolvable on a build without it.
        $this->app->bindIf(CapturesTransactionsForSync::class, NullTransactionSyncCapture::class);

        $this->app->bind(RecordsTransactions::class, RecordTransactions::class);
        $this->app->bind(UpdatesTransactionCategory::class, UpdateTransactionCategory::class);
        $this->app->bind(RecordsStatementSummary::class, StatementSummaryWriter::class);
        $this->app->bind(SavesTransactionSplit::class, SaveTransactionSplit::class);
        $this->app->bind(ReassignsCounterparty::class, ReassignCounterparty::class);
        $this->app->bind(SetsTransactionNote::class, SetTransactionNote::class);
        $this->app->singleton(FingerprintComposer::class);
        $this->app->singleton(CounterpartyKey::class);
        // Bound outright, with no null fallback: a probe that answered "no
        // keyed rows" because nothing was wired would hand a peer's key to
        // the device whose whole ledger is written under its own.
        $this->app->singleton(BlindIndexProvenance::class, CounterpartyKeyProvenance::class);
        // Transient, not singleton: it resolves the per-request CurrentUser, and
        // a singleton would freeze the first request's user in a long-lived worker.
        $this->app->bind(PeriodQuery::class);
        // Scoped for the same reason, from the other side: one render reaches it
        // for every money figure on the page, and the queue worker drops scoped
        // instances between jobs so the next job's reader is resolved afresh.
        $this->app->scoped(BaseCurrency::class);
        $this->app->bind(CategorySpendTrendQuery::class);
        $this->app->singleton(ThisPeriodAtAGlanceQuery::class);
        $this->app->singleton(TopCategoriesByPeriodQuery::class);
        $this->app->singleton(TransactionListQuery::class);
        $this->app->bind(FingerprintRederiveService::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $this->loadModuleResources('ledger');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');

        $livewire->component('ledger.transactions-list', TransactionsList::class);
        $livewire->component('ledger.transaction-detail', TransactionDetail::class);
        $livewire->component('ledger.account-currency-editor', AccountCurrencyEditor::class);

        // A migration cannot convert a sealed ledger, because the schema moves
        // at a moment no app-lock key is held. Every unlock is a moment one is,
        // so the pass is retried there until a user is recorded done.
        $events->listen(AppLockUnlocked::class, [SweepAsnDelimitersOnUnlock::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RederiveFingerprintsCommand::class,
            ]);
        }
    }
}
