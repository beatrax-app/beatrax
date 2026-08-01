<?php

declare(strict_types=1);

namespace Modules\Reports\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;

/**
 * @link ../../../.docs/features/reports/architecture.md
 */
final class ReportsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    private const REPORT_AGGREGATOR_CLASS = 'Modules\Reports\Internal\Aggregation\ReportAggregator';

    private const TIME_BUCKET_GENERATOR_CLASS = 'Modules\Reports\Internal\Aggregation\TimeBucketGenerator';

    private const REPORT_CSV_EXPORTER_CLASS = 'Modules\Reports\Internal\Services\ReportCsvExporter';

    private const SPEND_FILTER_APPLIER_CLASS = 'Modules\Reports\Internal\Aggregation\SpendFilterApplier';

    private const REPORT_DEFINITION_REQUEST_FACTORY_CLASS = 'Modules\Reports\Internal\Http\ReportDefinitionRequestFactory';

    private const SAVE_REPORT_CLASS = 'Modules\Reports\Public\Actions\SaveReport';

    private const UPDATE_REPORT_CLASS = 'Modules\Reports\Public\Actions\UpdateReport';

    private const DELETE_REPORT_CLASS = 'Modules\Reports\Public\Actions\DeleteReport';

    private const TOGGLE_PIN_CLASS = 'Modules\Reports\Public\Actions\TogglePin';

    private const REPORT_BUILDER_CLASS = 'Modules\Reports\Internal\Http\Livewire\ReportBuilder';

    private const REPORTS_INDEX_CLASS = 'Modules\Reports\Internal\Http\Livewire\ReportsIndex';

    private const PINNED_REPORTS_ROW_CLASS = 'Modules\Reports\Internal\Http\Livewire\PinnedReportsRow';

    public function register(): void
    {
        $this->singletonIfExists(self::REPORT_AGGREGATOR_CLASS);
        $this->singletonIfExists(self::TIME_BUCKET_GENERATOR_CLASS);
        $this->singletonIfExists(self::REPORT_CSV_EXPORTER_CLASS);
        // Stateless aggregation/HTTP collaborators — singletons avoid a
        // fresh instantiation per report run and per export request.
        $this->singletonIfExists(self::SPEND_FILTER_APPLIER_CLASS);
        $this->singletonIfExists(self::REPORT_DEFINITION_REQUEST_FACTORY_CLASS);
        // Actions are stateless — safe as singletons, avoiding a fresh
        // instantiation per request.
        $this->singletonIfExists(self::SAVE_REPORT_CLASS);
        $this->singletonIfExists(self::UPDATE_REPORT_CLASS);
        $this->singletonIfExists(self::DELETE_REPORT_CLASS);
        $this->singletonIfExists(self::TOGGLE_PIN_CLASS);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('reports');

        // Builder/index/pinned-row Livewire components, each registered
        // only once its class exists on disk.
        if (class_exists(self::REPORT_BUILDER_CLASS)) {
            $livewire->component('reports.report-builder', self::REPORT_BUILDER_CLASS);
        }
        if (class_exists(self::REPORTS_INDEX_CLASS)) {
            $livewire->component('reports.reports-index', self::REPORTS_INDEX_CLASS);
        }
        if (class_exists(self::PINNED_REPORTS_ROW_CLASS)) {
            $livewire->component('reports.pinned-reports-row', self::PINNED_REPORTS_ROW_CLASS);
        }
    }

    // The class name arrives as a runtime-built string so PHPStan does not
    // fold the class_exists() guard to an impossible type.
    private function singletonIfExists(string $class): void
    {
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }
}
