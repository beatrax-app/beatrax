<?php

declare(strict_types=1);

namespace Modules\Tax\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;
use Modules\Tax\Internal\Http\Livewire\TaxPage;
use Modules\Tax\Internal\Http\Livewire\TaxSettingsSection;
use Modules\Tax\Internal\Http\Livewire\TaxSummaryCard;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxCsvExporter;
use Modules\Tax\Public\Services\TaxPdfRenderer;
use Modules\Tax\Public\Services\TaxTagQuery;
use Modules\Tax\Public\Services\TaxYearQuery;

/**
 * Wires the Tax module: migrations, routes, views, Livewire components,
 * and ALL service singleton binds.
 *
 * Service bind inventory (by implementing plan):
 *   Plan 01: TagTransaction, UntagTransaction
 *   Plan 02: TaxYearQuery, TaxTagQuery
 *   Plan 03: TaxCsvExporter, TaxPdfRenderer
 *   Plan 04: TaxCorpusLoader (Internal), TaxCategoryWriter (Internal)
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

        // Plan 04 corpus + category writer (Internal classes)
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

        // Plan 04: Tax settings section (country choice + category CRUD)
        $livewire->component('tax.settings-section', TaxSettingsSection::class);

        // Plan 05: /tax cockpit page + dashboard summary card
        $livewire->component('tax.tax-page', TaxPage::class);
        $livewire->component('tax.summary-card', TaxSummaryCard::class);
    }
}
