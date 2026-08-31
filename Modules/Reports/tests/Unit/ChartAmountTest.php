<?php

declare(strict_types=1);

use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Support\ChartAmount;

// Every chart partial divided a minor amount by a hardcoded hundred. JPY has no
// minor unit, so a ¥1.000 row was plotted as 10 beside a table reading ¥1.000.

function catRow(int $minor, string $currency): ReportResultRow
{
    return new ReportResultRow(groupKey: 1, groupLabel: 'Groceries', amountMinor: $minor, currency: $currency);
}

it('plots a JPY row at its own scale rather than a hundredth of it', function (): void {
    expect(ChartAmount::series([catRow(1_000, 'JPY')]))->toBe([1000.0]);
});

it('still plots a two-decimal currency at a hundredth', function (): void {
    expect(ChartAmount::series([catRow(1_000, 'EUR')]))->toBe([10.0]);
});

it('keeps the sign on an axis series and drops it on a donut magnitude', function (): void {
    expect(ChartAmount::series([catRow(-2_450, 'EUR')]))->toBe([-24.5])
        ->and(ChartAmount::magnitudes([catRow(-2_450, 'EUR')]))->toBe([24.5]);
});

it('falls back to two decimals for a code no currency table knows', function (): void {
    expect(ChartAmount::majorUnits(1_000, 'ZZZ'))->toBe(10.0);
});
