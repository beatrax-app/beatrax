<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ComparisonJoin;

final class PeriodComparison
{
    /**
     * @param  list<ReportResultRow>  $currentRows
     * @param  callable(Period): ReportResultDto  $queryForPeriod  Re-runs the same dimension-query-plus-currency-mode pipeline (already carrying the driving definition's currency mode + filters) for an arbitrary Period, returning its full result (rows and FX-exclusion metadata).
     * @return array{rows: list<ReportResultRow>, previousExcludedCurrencies: list<string>, previousExcludedAccountIds: list<int>, previousTotalMinor: ?int, previousCurrency: string} rows is comparisonRows — previousAmountMinor/deltaMinor populated; the two previousExcluded* sets surface the previous period's own FX-exclusion state, and previousTotalMinor/previousCurrency its own headline total
     */
    public function compare(Period $currentPeriod, array $currentRows, callable $queryForPeriod, ComparisonJoin $join = ComparisonJoin::Group): array
    {
        $previousPeriod = $this->previousPeriod($currentPeriod);
        $previousResult = $queryForPeriod($previousPeriod);

        return [
            'rows' => $join === ComparisonJoin::Sequence
                ? self::joinBySequence($currentRows, $previousResult->rows)
                : self::joinByGroup($currentRows, $previousResult->rows),
            'previousExcludedCurrencies' => $previousResult->excludedCurrencies,
            'previousExcludedAccountIds' => $previousResult->excludedAccountIds,
            // A window that produced nothing is read the way the ROWS read it,
            // or one screen makes two claims about the same fact: every bucket
            // said "no counterpart" while the footer under them computed a
            // full-value delta off a previous total of zero.
            'previousTotalMinor' => $previousResult->rows === []
                ? $join->missingCounterpartMinor()
                : $previousResult->totalMinor,
            'previousCurrency' => $previousResult->currency,
        ];
    }

    /**
     * @param  list<ReportResultRow>  $currentRows
     * @param  list<ReportResultRow>  $previousRows
     * @return list<ReportResultRow>
     */
    private static function joinByGroup(array $currentRows, array $previousRows): array
    {
        /** @var array<string, array{key: int|string|null, label: string, currency: string, current: int, previous: int}> $byKey */
        $byKey = [];

        foreach ($currentRows as $row) {
            $byKey[self::keyFor($row)] = [
                'key' => $row->groupKey,
                'label' => $row->groupLabel,
                'currency' => $row->currency,
                'current' => $row->amountMinor,
                'previous' => 0,
            ];
        }

        foreach ($previousRows as $row) {
            $key = self::keyFor($row);
            if (! isset($byKey[$key])) {
                $byKey[$key] = [
                    'key' => $row->groupKey,
                    'label' => $row->groupLabel,
                    'currency' => $row->currency,
                    'current' => 0,
                    'previous' => 0,
                ];
            }
            $byKey[$key]['previous'] = $row->amountMinor;
        }

        $result = [];
        foreach ($byKey as $entry) {
            $result[] = new ReportResultRow(
                groupKey: $entry['key'],
                groupLabel: $entry['label'],
                amountMinor: $entry['current'],
                currency: $entry['currency'],
                previousAmountMinor: $entry['previous'],
                deltaMinor: $entry['current'] - $entry['previous'],
            );
        }

        usort($result, static fn (ReportResultRow $a, ReportResultRow $b): int => abs($b->deltaMinor ?? 0) <=> abs($a->deltaMinor ?? 0));

        return $result;
    }

    // A time bucket's and a net-worth point's group key is a DATE, which two
    // disjoint windows can never share: joining on it gave every current row a
    // previous of zero and appended every previous row as a fabricated current
    // one. Never re-sorted by delta either -- the row order IS the series.
    /**
     * @param  list<ReportResultRow>  $currentRows
     * @param  list<ReportResultRow>  $previousRows
     * @return list<ReportResultRow>
     */
    private static function joinBySequence(array $currentRows, array $previousRows): array
    {
        $previousByOrdinal = [];
        foreach (self::withOrdinals($previousRows) as $ordinal => $row) {
            $previousByOrdinal[$ordinal] = $row->amountMinor;
        }

        $result = [];
        foreach (self::withOrdinals($currentRows) as $ordinal => $row) {
            $previous = $previousByOrdinal[$ordinal] ?? ComparisonJoin::Sequence->missingCounterpartMinor();

            $result[] = new ReportResultRow(
                groupKey: $row->groupKey,
                groupLabel: $row->groupLabel,
                amountMinor: $row->amountMinor,
                currency: $row->currency,
                previousAmountMinor: $previous,
                deltaMinor: $previous === null ? null : $row->amountMinor - $previous,
            );
        }

        return $result;
    }

    // Ordinal within the row's own currency, since 'original' mode emits the
    // whole bucket run once per currency and the two windows need not have
    // discovered the same set of them.
    /**
     * @param  list<ReportResultRow>  $rows
     * @return array<string, ReportResultRow>
     */
    private static function withOrdinals(array $rows): array
    {
        $seen = [];
        $keyed = [];

        foreach ($rows as $row) {
            $position = $seen[$row->currency] ?? 0;
            $seen[$row->currency] = $position + 1;
            $keyed[$row->currency.'|'.$position] = $row;
        }

        return $keyed;
    }

    // A day-count shift is only correct for an arbitrary custom range: a
    // month-anchored preset would borrow days across Feb's 28 versus Mar's 31.
    public function previousPeriod(Period $period): Period
    {
        $monthsSpan = (int) $period->start->diffInMonths($period->endExclusive);

        if ($monthsSpan > 0 && $period->start->addMonthsNoOverflow($monthsSpan)->equalTo($period->endExclusive)) {
            $previousStart = $period->start->subMonthsNoOverflow($monthsSpan);

            return new Period(
                start: $previousStart,
                endExclusive: $period->start,
                label: 'Previous '.$period->label,
            );
        }

        // An arbitrary custom range, where a day-count shift is correct.
        // diffInDays() is whole here; the cast is only for its float return type.
        $days = (int) $period->start->diffInDays($period->endExclusive);

        return new Period(
            start: $period->start->subDays($days),
            endExclusive: $period->start,
            label: 'Previous '.$period->label,
        );
    }

    // Keyed by (group, currency): under 'original' mode CurrencyModeApplier
    // returns one row per currency, and keying on group alone would let the
    // second currency's row overwrite the first.
    private static function keyFor(ReportResultRow $row): string
    {
        $group = $row->groupKey === null ? 'null:'.$row->groupLabel : (string) $row->groupKey;

        return $group.'|'.$row->currency;
    }
}
