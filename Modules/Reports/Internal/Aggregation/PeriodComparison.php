<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;

final class PeriodComparison
{
    /**
     * @param  list<ReportResultRow>  $currentRows
     * @param  callable(Period): ReportResultDto  $queryForPeriod  Re-runs the same dimension-query-plus-currency-mode pipeline (already carrying the driving definition's currency mode + filters) for an arbitrary Period, returning its full result (rows and FX-exclusion metadata).
     * @return array{rows: list<ReportResultRow>, previousHasExcludedAccounts: bool, previousAccountsWithoutRate: int} rows is comparisonRows — union of current+previous groups, previousAmountMinor/deltaMinor populated, sorted by abs(delta) desc; previousHasExcludedAccounts/previousAccountsWithoutRate surface the previous period's own FX-exclusion state
     */
    public function compare(Period $currentPeriod, array $currentRows, callable $queryForPeriod): array
    {
        $previousPeriod = $this->previousPeriod($currentPeriod);
        $previousResult = $queryForPeriod($previousPeriod);
        $previousRows = $previousResult->rows;

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

        return [
            'rows' => $result,
            'previousHasExcludedAccounts' => $previousResult->hasExcludedAccounts,
            'previousAccountsWithoutRate' => $previousResult->accountsWithoutRate,
        ];
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
