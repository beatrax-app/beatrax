<?php

declare(strict_types=1);

use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Public\Http\Livewire\PinnedReportsRow;

// The donut card earns its legend because "hover is unavailable on a phone"
// (PinnedReportsRow::donutOptions). A bar or line card with no axis and no data
// labels is the same unreadable shape: bars of a colour, naming nothing.

/** @return list<ReportResultRow> */
function pinnedSeriesRows(): array
{
    return [
        new ReportResultRow(groupKey: 1, groupLabel: 'Jun 2026', amountMinor: -12500, currency: 'EUR'),
        new ReportResultRow(groupKey: 2, groupLabel: 'Jul 2026', amountMinor: -4200, currency: 'EUR'),
        new ReportResultRow(groupKey: 3, groupLabel: 'Aug 2026', amountMinor: -9800, currency: 'EUR'),
    ];
}

/** @return array<string, mixed> */
function pinnedSeriesOptions(string $viz): array
{
    $method = new ReflectionMethod(PinnedReportsRow::class, 'seriesOptions');

    /** @var array<string, mixed> $options */
    $options = $method->invoke(app(PinnedReportsRow::class), $viz, pinnedSeriesRows());

    return $options;
}

it('names the buckets on a pinned series card', function (string $viz): void {
    $options = pinnedSeriesOptions($viz);

    $axisNamed = ($options['xaxis']['labels']['show'] ?? false) === true;
    $labelsShown = ($options['dataLabels']['enabled'] ?? false) === true;

    expect($axisNamed || $labelsShown)->toBeTrue(
        "the {$viz} card names none of its buckets: x-axis labels off and dataLabels off",
    );
})->with([ReportViz::Bar->value, ReportViz::Line->value]);

it('keeps the bucket labels the card draws', function (): void {
    $options = pinnedSeriesOptions(ReportViz::Bar->value);

    expect($options['xaxis']['categories'] ?? [])->toBe(['Jun 2026', 'Jul 2026', 'Aug 2026']);
});
