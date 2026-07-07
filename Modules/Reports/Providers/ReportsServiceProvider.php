<?php

declare(strict_types=1);

namespace Modules\Reports\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Single-owner provider for the Reports module.
 *
 * CRITICAL: This provider is the SOLE owner of every Phase 999.6 binding
 * (mirrors the Phase 11 SyncServiceProvider / Phase 13.5 MigrationServiceProvider
 * single-owner precedent). Downstream plans in this phase never edit this
 * file — their classes are wired here behind `class_exists()` guards,
 * referenced by runtime-built FQCN string so this provider stays parseable
 * and PHPStan-clean before those classes exist. This file is written ONCE in
 * this Wave-0 plan and never re-edited by later plans.
 *
 * Service bind inventory (by implementing plan):
 *   Aggregation: ReportAggregator, TimeBucketGenerator
 *   CSV export: ReportCsvExporter
 *   Write actions: SaveReport, UpdateReport, DeleteReport, TogglePin
 *   Livewire components: reports.report-builder, reports.reports-index,
 *   reports.pinned-reports-row
 */
final class ReportsServiceProvider extends ServiceProvider
{
    private const REPORT_AGGREGATOR_CLASS = 'Modules\Reports\Internal\Aggregation\ReportAggregator';

    private const TIME_BUCKET_GENERATOR_CLASS = 'Modules\Reports\Internal\Aggregation\TimeBucketGenerator';

    private const REPORT_CSV_EXPORTER_CLASS = 'Modules\Reports\Internal\Services\ReportCsvExporter';

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
        // Actions are stateless — safe as singletons, mirrors StartMigrationRun.
        $this->singletonIfExists(self::SAVE_REPORT_CLASS);
        $this->singletonIfExists(self::UPDATE_REPORT_CLASS);
        $this->singletonIfExists(self::DELETE_REPORT_CLASS);
        $this->singletonIfExists(self::TOGGLE_PIN_CLASS);
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'reports');
        }

        // Downstream plans in this phase: builder/index/pinned-row Livewire
        // components. Registered the moment each class exists on disk, no
        // re-edit of this file needed.
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

    /**
     * Register a singleton for a class that may not exist yet (forward-looking
     * injection point for a later plan in this phase). The class name arrives
     * as a runtime-built string so PHPStan does not fold the class_exists()
     * guard to an impossible type. Copied verbatim from
     * Modules\Sync\Providers\SyncServiceProvider::singletonIfExists().
     */
    private function singletonIfExists(string $class): void
    {
        if (class_exists($class)) {
            $this->app->singleton($class);
        }
    }
}
