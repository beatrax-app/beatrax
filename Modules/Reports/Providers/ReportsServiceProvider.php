<?php

declare(strict_types=1);

namespace Modules\Reports\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Reports\Internal\Aggregation\SpendFilterApplier;
use Modules\Reports\Internal\Aggregation\TimeBucketGenerator;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Internal\Http\ReportDefinitionRequestFactory;
use Modules\Reports\Public\Http\Livewire\PinnedReportsRow;

final class ReportsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(TimeBucketGenerator::class);
        $this->app->singleton(SpendFilterApplier::class);
        $this->app->singleton(ReportDefinitionRequestFactory::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('reports');

        $livewire->component('reports.report-builder', ReportBuilder::class);
        $livewire->component('reports.reports-index', ReportsIndex::class);
        $livewire->component('reports.pinned-reports-row', PinnedReportsRow::class);
    }
}
