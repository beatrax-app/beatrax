<?php

declare(strict_types=1);

namespace Modules\Search\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

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
        if (class_exists(\Modules\Search\Internal\Services\SearchIndexWriter::class)) {
            $this->app->singleton(
                \Modules\Search\Public\Contracts\SearchIndexWriterContract::class,
                \Modules\Search\Internal\Services\SearchIndexWriter::class,
            );
        }

        // Plan 02: FTS health-check service (Public)
        if (class_exists(\Modules\Search\Public\Services\FtsHealthCheck::class)) {
            $this->app->singleton(\Modules\Search\Public\Services\FtsHealthCheck::class);
        }

        // Plan 03: SearchResultsProvider implementation (Internal)
        if (class_exists(\Modules\Search\Internal\Services\SearchResultsProviderImpl::class)) {
            $this->app->singleton(
                \Modules\Search\Public\Contracts\SearchResultsProvider::class,
                \Modules\Search\Internal\Services\SearchResultsProviderImpl::class,
            );
        }

        // Plan 03: Public SearchQuery service
        if (class_exists(\Modules\Search\Public\Services\SearchQuery::class)) {
            $this->app->singleton(\Modules\Search\Public\Services\SearchQuery::class);
        }

        // Plan 03: Internal query-pipeline services
        if (class_exists(\Modules\Search\Internal\Services\QueryParser::class)) {
            $this->app->singleton(\Modules\Search\Internal\Services\QueryParser::class);
        }

        if (class_exists(\Modules\Search\Internal\Services\EntityNameSearch::class)) {
            $this->app->singleton(\Modules\Search\Internal\Services\EntityNameSearch::class);
        }

        if (class_exists(\Modules\Search\Internal\Services\DidYouMeanSuggester::class)) {
            $this->app->singleton(\Modules\Search\Internal\Services\DidYouMeanSuggester::class);
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
        if (class_exists(\Modules\Search\Internal\Listeners\IndexTransactionOnImport::class)) {
            $events->listen(
                \Modules\Import\Public\Events\TransactionImported::class,
                [\Modules\Search\Internal\Listeners\IndexTransactionOnImport::class, 'handle'],
            );
        }

        // Plan 05: palette search endpoint Livewire component
        if (class_exists(\Modules\Search\Internal\Http\Livewire\PaletteSearchEndpoint::class)) {
            $livewire->component(
                'search.palette-search-endpoint',
                \Modules\Search\Internal\Http\Livewire\PaletteSearchEndpoint::class,
            );
        }
    }
}
