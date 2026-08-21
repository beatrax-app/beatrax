<?php

declare(strict_types=1);

namespace Modules\Tax\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;
use Modules\Tax\Internal\Http\Livewire\TaxPage;
use Modules\Tax\Internal\Listeners\InvalidateNavCounts;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Events\TransactionTagged;
use Modules\Tax\Public\Events\TransactionUntagged;
use Modules\Tax\Public\Http\Livewire\TaxSettingsSection;
use Modules\Tax\Public\Http\Livewire\TaxSummaryCard;
use Modules\Tax\Public\Services\TaxCountrySetup;
use Modules\Tax\Public\Services\TaxCsvExporter;
use Modules\Tax\Public\Services\TaxPdfRenderer;
use Modules\Tax\Public\Services\TaxTagQuery;
use Modules\Tax\Public\Services\TaxYearQuery;

final class TaxServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(TagTransaction::class);
        $this->app->singleton(UntagTransaction::class);

        $this->app->singleton(TaxYearQuery::class);
        $this->app->singleton(TaxTagQuery::class);

        $this->app->singleton(TaxCsvExporter::class);
        $this->app->singleton(TaxPdfRenderer::class);

        $this->app->singleton(TaxCorpusLoader::class);
        $this->app->singleton(TaxCategoryWriter::class);

        // The Public writer, not the Internal one imported above: this is what
        // HandlesTaxTagging resolves inside other modules' components.
        $this->app->singleton(\Modules\Tax\Public\Services\TaxCategoryWriter::class);

        $this->app->singleton(TaxCountrySetup::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        // Without this the sidebar tax_tagged badge stays stale for the cache TTL.
        $events->listen(TransactionTagged::class, [InvalidateNavCounts::class, 'handle']);
        $events->listen(TransactionUntagged::class, [InvalidateNavCounts::class, 'handle']);

        $this->loadModuleResources('tax');

        $livewire->component('tax.settings-section', TaxSettingsSection::class);

        $livewire->component('tax.tax-page', TaxPage::class);
        $livewire->component('tax.summary-card', TaxSummaryCard::class);
    }
}
