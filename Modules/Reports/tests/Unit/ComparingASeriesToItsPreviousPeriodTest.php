<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\PeriodComparison;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ComparisonJoin;

// A time bucket's group key is a DATE, so two disjoint windows never share one.
// Joining on it gave every current row previous = 0 and appended every previous
// row as a fabricated current row at 0: double the rows, half of them invented,
// and every delta equal to the raw value.

function casqRow(string $date, string $label, int $minor, string $currency = 'EUR'): ReportResultRow
{
    return new ReportResultRow(groupKey: $date, groupLabel: $label, amountMinor: $minor, currency: $currency);
}

function casqPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-04-01'),
        endExclusive: CarbonImmutable::parse('2026-07-01'),
        label: 'Q2',
    );
}

/**
 * @param  list<ReportResultRow>  $previousRows
 * @return callable(Period): ReportResultDto
 */
function casqPrevious(array $previousRows, int $totalMinor = 0, string $currency = 'EUR'): callable
{
    return static fn (Period $period): ReportResultDto => new ReportResultDto(
        rows: $previousRows,
        totalMinor: $totalMinor,
        currency: $currency,
    );
}

it('matches a time bucket against the previous window by position, since no date can be shared', function (): void {
    $current = [
        casqRow('2026-04-01', 'Apr 2026', 10_000),
        casqRow('2026-05-01', 'May 2026', 20_000),
        casqRow('2026-06-01', 'Jun 2026', 30_000),
    ];
    $previous = [
        casqRow('2026-01-01', 'Jan 2026', 8_000),
        casqRow('2026-02-01', 'Feb 2026', 9_000),
        casqRow('2026-03-01', 'Mar 2026', 40_000),
    ];

    $result = app(PeriodComparison::class)->compare(casqPeriod(), $current, casqPrevious($previous), ComparisonJoin::Sequence);

    expect($result['rows'])->toHaveCount(3);

    $labels = array_map(static fn (ReportResultRow $row): string => $row->groupLabel, $result['rows']);
    $previousAmounts = array_map(static fn (ReportResultRow $row): ?int => $row->previousAmountMinor, $result['rows']);
    $deltas = array_map(static fn (ReportResultRow $row): ?int => $row->deltaMinor, $result['rows']);

    expect($labels)->toBe(['Apr 2026', 'May 2026', 'Jun 2026'])
        ->and($previousAmounts)->toBe([8_000, 9_000, 40_000])
        ->and($deltas)->toBe([2_000, 11_000, -10_000]);
});

it('never fabricates a current row at zero out of a previous bucket', function (): void {
    $current = [casqRow('2026-04-01', 'Apr 2026', 10_000)];
    $previous = [
        casqRow('2026-03-01', 'Mar 2026', 8_000),
        casqRow('2026-03-08', 'Mar 8 2026', 7_000),
    ];

    $rows = app(PeriodComparison::class)->compare(casqPeriod(), $current, casqPrevious($previous), ComparisonJoin::Sequence)['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->amountMinor)->toBe(10_000);
});

it('says nothing rather than zero for a bucket the previous window does not reach', function (): void {
    $current = [
        casqRow('2026-04-01', 'Apr 2026', 10_000),
        casqRow('2026-05-01', 'May 2026', 20_000),
    ];

    $rows = app(PeriodComparison::class)->compare(casqPeriod(), $current, casqPrevious([casqRow('2026-01-01', 'Jan 2026', 8_000)]), ComparisonJoin::Sequence)['rows'];

    expect($rows[1]->previousAmountMinor)->toBeNull()
        ->and($rows[1]->deltaMinor)->toBeNull();
});

it('keeps a series in its own order instead of re-sorting it by delta', function (): void {
    $current = [
        casqRow('2026-04-01', 'Apr 2026', 100),
        casqRow('2026-05-01', 'May 2026', 90_000),
        casqRow('2026-06-01', 'Jun 2026', 200),
    ];
    $previous = [
        casqRow('2026-01-01', 'Jan 2026', 0),
        casqRow('2026-02-01', 'Feb 2026', 0),
        casqRow('2026-03-01', 'Mar 2026', 0),
    ];

    $rows = app(PeriodComparison::class)->compare(casqPeriod(), $current, casqPrevious($previous), ComparisonJoin::Sequence)['rows'];

    expect(array_map(static fn (ReportResultRow $row): string => $row->groupLabel, $rows))
        ->toBe(['Apr 2026', 'May 2026', 'Jun 2026']);
});

// 'original' mode emits the whole bucket run once per currency, and the two
// windows need not have discovered the same set of them.
it('counts a bucket position within its own currency', function (): void {
    $current = [
        casqRow('2026-04-01', 'Apr 2026', 10_000, 'EUR'),
        casqRow('2026-05-01', 'May 2026', 20_000, 'EUR'),
        casqRow('2026-04-01', 'Apr 2026', 500, 'USD'),
        casqRow('2026-05-01', 'May 2026', 600, 'USD'),
    ];
    $previous = [
        casqRow('2026-02-01', 'Feb 2026', 1_000, 'EUR'),
        casqRow('2026-03-01', 'Mar 2026', 2_000, 'EUR'),
        casqRow('2026-02-01', 'Feb 2026', 50, 'USD'),
        casqRow('2026-03-01', 'Mar 2026', 60, 'USD'),
    ];

    $rows = app(PeriodComparison::class)->compare(casqPeriod(), $current, casqPrevious($previous), ComparisonJoin::Sequence)['rows'];

    expect(array_map(static fn (ReportResultRow $row): ?int => $row->previousAmountMinor, $rows))
        ->toBe([1_000, 2_000, 50, 60]);
});

it('still joins a category dimension on its group key', function (): void {
    $current = [
        new ReportResultRow(groupKey: 7, groupLabel: 'Groceries', amountMinor: 10_000, currency: 'EUR'),
    ];
    $previous = [
        new ReportResultRow(groupKey: 7, groupLabel: 'Groceries', amountMinor: 6_000, currency: 'EUR'),
        new ReportResultRow(groupKey: 9, groupLabel: 'Travel', amountMinor: 3_000, currency: 'EUR'),
    ];

    $rows = app(PeriodComparison::class)->compare(casqPeriod(), $current, casqPrevious($previous), ComparisonJoin::Group)['rows'];

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->groupLabel)->toBe('Groceries')
        ->and($rows[0]->deltaMinor)->toBe(4_000);
});

// The headline delta used to be re-derived by summing the rows' own previous
// amounts, which adds currencies together and adds a balance up once per bucket.
it('reports the previous window own headline total rather than leaving it to be re-summed', function (): void {
    $comparison = app(PeriodComparison::class)->compare(
        casqPeriod(),
        [casqRow('2026-04-01', 'Apr 2026', 10_000)],
        casqPrevious([casqRow('2026-01-01', 'Jan 2026', 8_000)], totalMinor: 722_080, currency: 'EUR'),
        ComparisonJoin::Sequence,
    );

    expect($comparison['previousTotalMinor'])->toBe(722_080)
        ->and($comparison['previousCurrency'])->toBe('EUR');
});
