<?php

declare(strict_types=1);

namespace Modules\Reports\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Reports\Internal\Actions\DeleteReport;
use Modules\Reports\Internal\Actions\SaveReport;
use Modules\Reports\Internal\Actions\TogglePin;
use Modules\Reports\Internal\Actions\UpdateReport;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Aggregation\SpendFilterApplier;
use Modules\Reports\Internal\Aggregation\TimeBucketGenerator;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Internal\Http\ReportDefinitionRequestFactory;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Modules\Reports\Public\Http\Livewire\PinnedReportsRow;

final class ReportsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(ReportAggregator::class);
        $this->app->singleton(TimeBucketGenerator::class);
        $this->app->singleton(ReportCsvExporter::class);
        $this->app->singleton(SpendFilterApplier::class);
        $this->app->singleton(ReportDefinitionRequestFactory::class);
        $this->app->singleton(SaveReport::class);
        $this->app->singleton(UpdateReport::class);
        $this->app->singleton(DeleteReport::class);
        $this->app->singleton(TogglePin::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('reports');

        $livewire->component('reports.report-builder', ReportBuilder::class);
        $livewire->component('reports.reports-index', ReportsIndex::class);
        $livewire->component('reports.pinned-reports-row', PinnedReportsRow::class);
    }
}
