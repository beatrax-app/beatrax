<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultDto;
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
     * @param  callable(Period): ReportResultDto  $queryForPeriod  Re-runs the SAME dimension-query-plus-currency-mode pipeline (already carrying the driving definition's currency mode + filters) for an arbitrary Period, returning its full result (rows AND FX-exclusion metadata).
     * @return array{rows: list<ReportResultRow>, previousHasExcludedAccounts: bool, previousAccountsWithoutRate: int} rows is comparisonRows — union of current+previous groups, previousAmountMinor/deltaMinor populated, sorted by abs(delta) desc. previousHasExcludedAccounts/previousAccountsWithoutRate (WR-04) surface the PREVIOUS period's own FX-exclusion state, which is otherwise invisible if only the current period's flags are trusted.
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

    /**
     * The immediately-preceding window of the SAME shape as the current
     * period — works uniformly for every preset (this_month, last_N_months,
     * ytd/this_year, custom).
     *
     * CR-01: a plain equal-length DAY-count shift is only correct for a
     * true arbitrary-length custom range. Every month-anchored preset
     * (this_month / last_N_months / ytd / this_year — all produced by
     * `PeriodPresetResolver` via `PeriodQuery`'s `addMonthNoOverflow()`-
     * based stepping) starts on a fixed day-of-month and ends an exact
     * number of calendar months later, so the previous period must be
     * derived by stepping back the SAME number of calendar months
     * (mirroring `PeriodQuery`'s own `subMonthNoOverflow()`/
     * `addMonthNoOverflow()` idiom) — never by re-applying the current
     * period's own day-count, which "borrows" days across a shorter/longer
     * adjacent month (e.g. Feb's 28 days vs Mar's 31).
     */
    public function previousPeriod(Period $period): Period
    {
        $monthsSpan = (int) $period->start->diffInMonths($period->endExclusive);

        // Month-aligned span: stepping the start forward by its own month
        // count lands exactly on endExclusive -> step back by the same
        // number of calendar months instead of a raw day-count shift.
        if ($monthsSpan > 0 && $period->start->addMonthsNoOverflow($monthsSpan)->equalTo($period->endExclusive)) {
            $previousStart = $period->start->subMonthsNoOverflow($monthsSpan);

            return new Period(
                start: $previousStart,
                endExclusive: $period->start,
                label: 'Previous '.$period->label,
            );
        }

        // True arbitrary-length custom range: a plain day-count shift is
        // correct here. INFO-02: diffInDays() already returns a whole
        // number for these start-of-day period boundaries, so the extra
        // round() this used to go through was dead code — only the (int)
        // cast is needed (the installed Carbon version's diffInDays()
        // return type is float, not int).
        $days = (int) $period->start->diffInDays($period->endExclusive);

        return new Period(
            start: $period->start->subDays($days),
            endExclusive: $period->start,
            label: 'Previous '.$period->label,
        );
    }

    /**
     * CR-03: keyed by (group, currency), never group alone. Under
     * `currencyMode: 'original'`, `CurrencyModeApplier::applyOriginal()`
     * intentionally returns one row PER currency for a group present in
     * more than one currency (never merged — see its own docblock). Keying
     * by group alone would let a second currency's row silently overwrite
     * the first in `$byKey` below instead of both surfacing as distinct
     * comparison rows.
     */
    private static function keyFor(ReportResultRow $row): string
    {
        $group = $row->groupKey === null ? 'null:'.$row->groupLabel : (string) $row->groupKey;

        return $group.'|'.$row->currency;
    }
}
