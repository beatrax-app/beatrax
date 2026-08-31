<?php

declare(strict_types=1);

use Livewire\Finder\Finder;
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

// A binding written as a runtime-built FQCN string is invisible to static
// analysis, and a typo in one used to be swallowed by a class_exists() guard.
// These pin that the container can still build what the provider names.
//
// Buildable, not identical: a stateless service that dispatches events is
// deliberately not a singleton any more, because Event::fake() cannot reach a
// dispatcher one already holds. ASingletonNeverCapturesTheDispatcher keeps
// that invariant; this keeps the wiring honest.

it('builds every Reports service the provider names', function (string $class): void {
    expect(app($class))->toBeInstanceOf($class);
})->with([
    ReportAggregator::class,
    TimeBucketGenerator::class,
    ReportCsvExporter::class,
    SpendFilterApplier::class,
    ReportDefinitionRequestFactory::class,
    SaveReport::class,
    UpdateReport::class,
    DeleteReport::class,
    TogglePin::class,
]);

it('resolves every Reports Livewire tag to its class', function (string $tag, string $class): void {
    /** @var Finder $finder */
    $finder = app('livewire.finder');

    expect($finder->resolveClassComponentClassName($tag))->toBe($class);
})->with([
    ['reports.report-builder', ReportBuilder::class],
    ['reports.reports-index', ReportsIndex::class],
    ['reports.pinned-reports-row', PinnedReportsRow::class],
]);
