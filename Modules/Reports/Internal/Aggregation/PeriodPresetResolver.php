<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * Resolves a `ReportDefinition->periodPreset` string into a concrete,
 * half-open `Period` window (Req 6).
 *
 * `this_month` and the `last_N_months` presets delegate to
 * `Modules\Ledger\Public\Services\PeriodQuery` — the user's existing
 * `period_start_day`-anchored month-stepping algorithm — so a report's
 * "this month" always matches the dashboard's "this month" exactly.
 * `ytd`/`this_year`/`custom` are built directly from the injected `Clock`
 * (never the `now()`/`Carbon::now()` global helpers — `noGlobalLaravelFunction`).
 */
final class PeriodPresetResolver
{
    public function __construct(
        private readonly PeriodQuery $periodQuery,
        private readonly Clock $clock,
    ) {}

    public function resolve(string $preset, ?string $customFrom = null, ?string $customTo = null): Period
    {
        return match ($preset) {
            'this_month' => $this->periodQuery->current(),
            'last_3_months' => $this->lastNMonths(3),
            'last_6_months' => $this->lastNMonths(6),
            'last_12_months' => $this->lastNMonths(12),
            'ytd', 'this_year' => $this->yearWindow($preset),
            'custom' => $this->custom($customFrom, $customTo),
            default => throw new InvalidArgumentException("Unknown period preset: {$preset}"),
        };
    }

    /**
     * "Last N months" = the current period unioned with the (N-1) prior
     * periods reached by walking `PeriodQuery::previous()` — start at the
     * earliest window reached, end at the current window's own
     * `endExclusive` (999.6-PATTERNS.md Code Example 1).
     */
    private function lastNMonths(int $months): Period
    {
        $current = $this->periodQuery->current();
        $earliest = $current;

        for ($i = 1; $i < $months; $i++) {
            $earliest = $this->periodQuery->previous($earliest);
        }

        return new Period(
            start: $earliest->start,
            endExclusive: $current->endExclusive,
            label: "Last {$months} months",
        );
    }

    /**
     * `ytd` and `this_year` both resolve to `startOfYear() -> now+1day`
     * (999.6-RESEARCH.md Code Example 1 / 999.6-04-PLAN.md Task 1 action):
     * a future calendar date never carries transactions, so a full
     * calendar-year window and a year-to-date window observably coincide.
     * Kept as two distinct preset keys purely for the picker's copy
     * ("Year to date" vs "This year") — never diverge this formula between
     * the two branches without also updating this comment.
     */
    private function yearWindow(string $preset): Period
    {
        $now = $this->clock->now();
        $start = $now->startOfYear()->startOfDay();
        $endExclusive = $now->addDay()->startOfDay();
        $label = $preset === 'ytd' ? 'Year to date' : (string) $now->year;

        return new Period(start: $start, endExclusive: $endExclusive, label: $label);
    }

    /**
     * Pitfall 2(a): the user picks an INCLUSIVE end date in the custom-range
     * control, but `Period.endExclusive` is half-open by contract — convert
     * by adding one day so the selected end date's own transactions are
     * never silently dropped from the report's totals.
     */
    private function custom(?string $customFrom, ?string $customTo): Period
    {
        if ($customFrom === null || $customFrom === '' || $customTo === null || $customTo === '') {
            throw new InvalidArgumentException('The "custom" period preset requires both customFrom and customTo dates.');
        }

        $start = CarbonImmutable::parse($customFrom)->startOfDay();
        $inclusiveEnd = CarbonImmutable::parse($customTo)->startOfDay();
        $endExclusive = $inclusiveEnd->addDay();

        return new Period(
            start: $start,
            endExclusive: $endExclusive,
            label: $start->toDateString().' → '.$inclusiveEnd->toDateString(),
        );
    }
}
