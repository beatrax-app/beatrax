<?php

declare(strict_types=1);

namespace Modules\Search\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Search\Internal\Console\ReindexSearchCommand;
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
use Modules\Search\Public\Http\Livewire\PaletteSearchEndpoint;
use Modules\Search\Public\Services\FtsHealthCheck;
use Modules\Search\Public\Services\SearchQuery;

final class SearchServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(
            SearchIndexWriterContract::class,
            SearchIndexWriter::class,
        );

        $this->app->singleton(FtsHealthCheck::class);

        $this->app->singleton(
            SearchResultsProvider::class,
            SearchResultsProviderImpl::class,
        );

        $this->app->singleton(FtsCandidateResolver::class);

        $this->app->singleton(SearchRowMapper::class);

        $this->app->singleton(SearchQuery::class);

        $this->app->singleton(QueryParser::class);

        $this->app->singleton(EntityNameSearch::class);

        $this->app->singleton(DidYouMeanSuggester::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $this->loadModuleResources('search');

        $events->listen(
            TransactionImported::class,
            [IndexTransactionOnImport::class, 'handle'],
        );

        if ($this->app->runningInConsole()) {
            $this->commands([ReindexSearchCommand::class]);
        }

        $livewire->component(
            'search.palette-search-endpoint',
            PaletteSearchEndpoint::class,
        );
    }
}
