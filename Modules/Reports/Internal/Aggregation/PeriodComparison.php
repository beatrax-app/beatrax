<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultRow;

/**
 * Period-over-period comparison (Req 13) — computes the previous-equivalent
 * `Period` for an arbitrary report `Period` and joins it against the
 * current period's rows by group key.
 *
 * The previous period is a plain equal-length span-shift ending exactly at
 * the current period's `start`, NOT `Modules\Ledger\Public\Services\
 * PeriodQuery::previous()` — that method re-derives its window from
 * `CurrentUser::periodStartDay()` (the CURRENT session user, which may not
 * even be the `User` this report is running for) and only means "the
 * calendar month before" for the `this_month` preset; it would silently
 * disagree with a `last_6_months`/`ytd`/`custom` report's own window.
 *
 * Reimplements `Modules\Ledger\Public\Services\CategorySpendTrendQuery`'s
 * current-vs-previous MOVER SHAPE generically (999.6-RESEARCH.md Code
 * Example 5) rather than importing/parametrizing that class (it is
 * hard-wired to categories + `PeriodQuery::current()`/`previous()`): union
 * of current AND previous group keys — a group that dropped to zero this
 * period (or only appeared in the previous period) still surfaces as a
 * mover, exactly like the dashboard trend card — sorted by
 * `abs(deltaMinor)` descending.
 *
 * Currency-mode-agnostic by design: the caller's `$queryForPeriod` closure
 * already carries whichever `CurrencyModeApplier` call (base/original) and
 * filters produced the current period's rows, so re-running it for the
 * previous period automatically honors the SAME currency mode with zero
 * duplicated definition logic here.
 */
final class PeriodComparison
{
    /**
     * @param  list<ReportResultRow>  $currentRows
     * @param  callable(Period): list<ReportResultRow>  $queryForPeriod  Re-runs the SAME dimension query (already carrying the driving definition's currency mode + filters) for an arbitrary Period.
     * @return list<ReportResultRow> comparisonRows — union of current+previous groups, previousAmountMinor/deltaMinor populated, sorted by abs(delta) desc
     */
    public function compare(Period $currentPeriod, array $currentRows, callable $queryForPeriod): array
    {
        $previousPeriod = $this->previousPeriod($currentPeriod);
        $previousRows = $queryForPeriod($previousPeriod);

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

    /**
     * A plain equal-length span-shift immediately preceding the current
     * period's start — works uniformly for every preset (this_month,
     * last_N_months, ytd/this_year, custom).
     */
    public function previousPeriod(Period $period): Period
    {
        $days = (int) round($period->start->diffInDays($period->endExclusive));
        $previousStart = $period->start->subDays($days);

        return new Period(
            start: $previousStart,
            endExclusive: $period->start,
            label: 'Previous '.$period->label,
        );
    }

    private static function keyFor(ReportResultRow $row): string
    {
        return $row->groupKey === null ? 'null:'.$row->groupLabel : (string) $row->groupKey;
    }
}
