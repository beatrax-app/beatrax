<?php

declare(strict_types=1);

namespace Modules\Search\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Search\Internal\Http\Livewire\PaletteSearchEndpoint;
use Modules\Search\Internal\Listeners\IndexTransactionOnImport;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Internal\Services\QueryParser;
use Modules\Search\Internal\Services\SearchIndexWriter;
use Modules\Search\Internal\Services\SearchResultsProviderImpl;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Services\FtsHealthCheck;
use Modules\Search\Public\Services\SearchQuery;

/**
 * Single owner of all Search module bindings and registrations.
 *
 * CRITICAL: This provider is the ONLY file Plans 02, 03, and 05 ever
 * need from Plan 01. They create the named classes; this provider
 * automatically wires them via class_exists()-guarded blocks.
 * No downstream plan edits this file.
 *
 * Service bind inventory (by implementing plan):
 *   Plan 02: SearchIndexWriter (Internal), IndexTransactionOnImport (Internal),
 *            FtsHealthCheck (Public)
 *   Plan 03: SearchQuery (Public), QueryParser (Internal),
 *            EntityNameSearch (Internal), DidYouMeanSuggester (Internal),
 *            SearchResultsProvider impl (Internal)
 *   Plan 05: PaletteSearchEndpoint Livewire component (Internal)
 */
final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 02: index writer + listener
        if (class_exists(SearchIndexWriter::class)) {
            $this->app->singleton(
                SearchIndexWriterContract::class,
                SearchIndexWriter::class,
            );
        }

        // Plan 02: FTS health-check service (Public)
        if (class_exists(FtsHealthCheck::class)) {
            $this->app->singleton(FtsHealthCheck::class);
        }

        // Plan 03: SearchResultsProvider implementation (Internal)
        if (class_exists(SearchResultsProviderImpl::class)) {
            $this->app->singleton(
                SearchResultsProvider::class,
                SearchResultsProviderImpl::class,
            );
        }

        // Plan 03: Public SearchQuery service
        if (class_exists(SearchQuery::class)) {
            $this->app->singleton(SearchQuery::class);
        }

        // Plan 03: Internal query-pipeline services
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

        // Plan 02: index the transaction on import (synchronous, D-23)
        if (class_exists(IndexTransactionOnImport::class)) {
            $events->listen(
                TransactionImported::class,
                [IndexTransactionOnImport::class, 'handle'],
            );
        }

        // Plan 05: palette search endpoint Livewire component
        if (class_exists(PaletteSearchEndpoint::class)) {
            $livewire->component(
                'search.palette-search-endpoint',
                PaletteSearchEndpoint::class,
            );
        }
    }
}
