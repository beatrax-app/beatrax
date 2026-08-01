<?php

declare(strict_types=1);

namespace Modules\Search\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Search\Internal\Console\ReindexSearchCommand;
use Modules\Search\Internal\Http\Livewire\PaletteSearchEndpoint;
use Modules\Search\Internal\Listeners\IndexTransactionOnImport;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Internal\Services\FtsCandidateResolver;
use Modules\Search\Internal\Services\QueryParser;
use Modules\Search\Internal\Services\SearchIndexWriter;
use Modules\Search\Internal\Services\SearchResultsProviderImpl;
use Modules\Search\Internal\Services\SearchRowMapper;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Services\FtsHealthCheck;
use Modules\Search\Public\Services\SearchQuery;

// Single owner of every Search module binding + registration; every
// class here is wired via class_exists()-guarded blocks so no
// downstream change ever needs to edit this file directly.
/**
 * @link ../../../.docs/features/search/architecture.md
 */
final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (class_exists(SearchIndexWriter::class)) {
            $this->app->singleton(
                SearchIndexWriterContract::class,
                SearchIndexWriter::class,
            );
        }

        if (class_exists(FtsHealthCheck::class)) {
            $this->app->singleton(FtsHealthCheck::class);
        }

        if (class_exists(SearchResultsProviderImpl::class)) {
            $this->app->singleton(
                SearchResultsProvider::class,
                SearchResultsProviderImpl::class,
            );
        }

        if (class_exists(FtsCandidateResolver::class)) {
            $this->app->singleton(FtsCandidateResolver::class);
        }

        if (class_exists(SearchRowMapper::class)) {
            $this->app->singleton(SearchRowMapper::class);
        }

        if (class_exists(SearchQuery::class)) {
            $this->app->singleton(SearchQuery::class);
        }

        if (class_exists(QueryParser::class)) {
            $this->app->singleton(QueryParser::class);
        }

        if (class_exists(EntityNameSearch::class)) {
            $this->app->singleton(EntityNameSearch::class);
        }

        if (class_exists(DidYouMeanSuggester::class)) {
            $this->app->singleton(DidYouMeanSuggester::class);
        }
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }

        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'search');
        }

        if (is_dir(__DIR__.'/../Resources/lang')) {
            $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'search');
        }

        if (class_exists(IndexTransactionOnImport::class)) {
            $events->listen(
                TransactionImported::class,
                [IndexTransactionOnImport::class, 'handle'],
            );
        }

        if (class_exists(ReindexSearchCommand::class) && $this->app->runningInConsole()) {
            $this->commands([ReindexSearchCommand::class]);
        }

        if (class_exists(PaletteSearchEndpoint::class)) {
            $livewire->component(
                'search.palette-search-endpoint',
                PaletteSearchEndpoint::class,
            );
        }
    }
}
