<?php

declare(strict_types=1);

use Modules\Reports\Internal\Http\Livewire\PinnedReportsRow;
use Modules\Reports\Public\Dto\ReportResultRow;

/*
 * A donut with dataLabels off AND legend off is a ring of colours that says
 * nothing. On the dashboard there is no axis to carry the meaning and no hover
 * on a phone, so the card gave the user no way to tell which slice was which.
 *
 * Asserted against the options the component actually builds. Reading the
 * source for "'show' => true" matched any later occurrence in the file, and the
 * negative case matched one exact spelling that a reformat would defeat.
 */

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
