<?php

declare(strict_types=1);

namespace Modules\Tax\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Events\UserCountryChanged;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Tax\Internal\Actions\TaxCategoryStore;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;
use Modules\Tax\Internal\Http\Livewire\TaxPage;
use Modules\Tax\Internal\Listeners\InvalidateNavCounts;
use Modules\Tax\Internal\Listeners\SeedDeductionCategoriesForCountry;
use Modules\Tax\Internal\Services\TaxCsvExporter;
use Modules\Tax\Internal\Services\TaxPdfRenderer;
use Modules\Tax\Internal\Services\TaxYearQuery;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Events\TransactionTagged;
use Modules\Tax\Public\Events\TransactionUntagged;
use Modules\Tax\Public\Http\Livewire\TaxSettingsSection;
use Modules\Tax\Public\Http\Livewire\TaxSummaryCard;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Modules\Tax\Public\Services\TaxTagQuery;

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
        $this->app->singleton(TaxCategoryStore::class);

        // The capturing writer, not the row store above: every category write
        // the reader can reach goes through here, or a tag reaches the peer
        // pointing at a category row that peer never received.
        $this->app->singleton(TaxCategoryWriter::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $events->listen(TransactionTagged::class, [InvalidateNavCounts::class, 'handle']);
        $events->listen(TransactionUntagged::class, [InvalidateNavCounts::class, 'handle']);

        // The country is a user preference now, set from signup, Settings or the
        // wizard — none of which knows Tax exists. Without this listener every
        // one of those routes leaves the deduction categories empty.
        $events->listen(UserCountryChanged::class, [SeedDeductionCategoriesForCountry::class, 'handle']);

        // Tax was the one seeding module not on this event, because its corpus
        // hangs off the country rather than off the install. That left the
        // install-time heal InstallCommand documents — and the phone's own way
        // out of an abandoned import — with no way to make this corpus good.
        $events->listen(UserInstalled::class, [SeedDeductionCategoriesForCountry::class, 'handleInstall']);

        $this->loadModuleResources('tax');

        $livewire->component('tax.settings-section', TaxSettingsSection::class);

        $livewire->component('tax.tax-page', TaxPage::class);
        $livewire->component('tax.summary-card', TaxSummaryCard::class);
    }
}
