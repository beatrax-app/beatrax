<?php

declare(strict_types=1);

use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Public\Http\Livewire\PinnedReportsRow;

// A donut with dataLabels and legend both off is a ring of colours: no axis on
// the card, no hover on a phone. Asserted against the built options, because
// grepping for "'show' => true" matched later occurrences and broke on reformat.

it('never draws a donut with neither a legend nor data labels', function (): void {
    $rows = [
        new ReportResultRow(groupKey: 1, groupLabel: 'Groceries', amountMinor: -12500, currency: 'EUR'),
        new ReportResultRow(groupKey: 2, groupLabel: 'Transport', amountMinor: -4200, currency: 'EUR'),
    ];

    $method = new ReflectionMethod(PinnedReportsRow::class, 'donutOptions');
    /** @var array<string, mixed> $options */
    $options = $method->invoke(app(PinnedReportsRow::class), $rows);

    $legendShown = ($options['legend']['show'] ?? false) === true;
    $labelsShown = ($options['dataLabels']['enabled'] ?? false) === true;

    expect($legendShown || $labelsShown)->toBeTrue(
        'the donut card names none of its slices: legend off and dataLabels off',
    );
});

it('gives the legend the room it needs to be read', function (): void {
    $rows = [new ReportResultRow(groupKey: 1, groupLabel: 'Groceries', amountMinor: -12500, currency: 'EUR')];

    $method = new ReflectionMethod(PinnedReportsRow::class, 'donutOptions');
    /** @var array<string, mixed> $options */
    $options = $method->invoke(app(PinnedReportsRow::class), $rows);

    // A legend below the ring only helps if the card is tall enough to show it.
    expect($options['legend']['position'] ?? null)->toBe('bottom')
        ->and($options['chart']['height'] ?? 0)->toBeGreaterThan(0);
});
