<?php

declare(strict_types=1);

namespace Modules\Tax\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Modules\Tax\Public\Services\TaxCorpusLoader;
use Modules\Tax\Public\Services\TaxCsvExporter;
use Modules\Tax\Public\Services\TaxPdfRenderer;
use Modules\Tax\Public\Services\TaxTagQuery;
use Modules\Tax\Public\Services\TaxYearQuery;

/**
 * Wires the Tax module: migrations, routes, views, and ALL service singleton
 * binds. Every Tax service class is pre-bound here in register() — lazily —
 * so downstream plans (02–05) that ship the actual service classes never need
 * to touch this file. Lazy binds are safe: the container only resolves the
 * class when it is first requested, not at registration time, so binding a
 * not-yet-existing class here does NOT break boot.
 *
 * Service bind inventory (by implementing plan):
 *   Plan 01: TagTransaction, UntagTransaction
 *   Plan 02: TaxYearQuery, TaxTagQuery
 *   Plan 03: TaxCsvExporter, TaxPdfRenderer
 *   Plan 04: TaxCorpusLoader, TaxCategoryWriter
 */
final class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 01 actions
        $this->app->singleton(TagTransaction::class);
        $this->app->singleton(UntagTransaction::class);

        // Plan 02 query services
        $this->app->singleton(TaxYearQuery::class);
        $this->app->singleton(TaxTagQuery::class);

        // Plan 03 exporters
        $this->app->singleton(TaxCsvExporter::class);
        $this->app->singleton(TaxPdfRenderer::class);

        // Plan 04 corpus + category writer
        $this->app->singleton(TaxCorpusLoader::class);
        $this->app->singleton(TaxCategoryWriter::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }

        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }

        $viewsPath = __DIR__.'/../Resources/views';
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'tax');
        }

        // TODO: Plan 04 adds TaxSettingsPage component registration here.
        // TODO: Plan 05 adds TaxPage component registration here.
    }
}
