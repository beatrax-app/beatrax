<?php

declare(strict_types=1);

namespace Modules\Position\Public\Services;

use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * The single Public definition of "your position" (D-30): net worth +
 * budgets + upcoming charges + shortfalls, COMPOSED from other modules'
 * existing Public seams. This class never issues a cross-module raw SELECT
 * — every figure is read through the owning module's own Public query:
 *
 *   - `Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery` — the
 *     dashboard's existing "this period at a glance" composer (`for()` /
 *     `forByCurrency()` / `emailScanHealth()`), already Public.
 *   - `Modules\Budgets\Public\Services\BudgetProgressQuery` — budget status.
 *   - `Modules\Recurring\Public\Services\RecurringSeriesQuery` — upcoming
 *     charges (approved series whose `nextExpectedAt` falls inside the
 *     queried period).
 *   - `Modules\Forecasting\Public\Services\ForecastHighlightsQuery` — the
 *     shortfall signal (`activeShortfallCountForUser()`), which is
 *     Forecasting's own Public seam over `ForecastShortfallDetected`'s
 *     underlying `forecast_shortfall_windows` data.
 *
 * `forUser()` is the digest's (this plan, Task 2/3) and — from plan 18-18
 * onward — the dashboard's single source of "position" data, so the two
 * surfaces can never silently disagree (D-30's whole reason for existing).
 * Explicit `User` argument throughout; no implicit `CurrentUser` resolution,
 * so this class is safe to call from queue/console contexts where the
 * `BelongsToUser` global scope does not fire.
 *
 * D-21: even a user with zero transactions, zero budgets, zero upcoming
 * charges and no shortfall gets a fully-populated DTO back — "nothing
 * notable" is itself a valid position, never null.
 */
final readonly class PositionQuery
{
    public function __construct(
        private ThisPeriodAtAGlanceQuery $glance,
        private BudgetProgressQuery $budgets,
        private RecurringSeriesQuery $recurringSeries,
        private ForecastHighlightsQuery $forecastHighlights,
    ) {}

    public function forUser(User $user, Period $period): PositionSummaryDto
    {
        $summary = $this->glance->for($user, $period);

        // Mirrors the dashboard Livewire component's exact toggle (Pitfall 3
        // / D-30 guard 2): per-currency tiles ONLY in 'original' mode, null
        // (hidden) otherwise. Keeping this condition byte-identical to the
        // dashboard's is what makes 18-18's eventual seam swap a pure no-op.
        $tilesByCurrency = $user->default_currency_view === 'original'
            ? $this->glance->forByCurrency($user, $period)
            : null;

        $emailScanHealth = $this->glance->emailScanHealth($user);

        return new PositionSummaryDto(
            summary: $summary,
            tilesByCurrency: $tilesByCurrency,
            emailScanHealth: $emailScanHealth,
            upcoming: $this->upcomingRecurringCharges($user, $period),
            budgets: $this->budgets->forCurrentPeriod($user),
            shortfallAhead: $this->forecastHighlights->activeShortfallCountForUser($user) > 0,
        );
    }

    /**
     * Approved (and cadence-changed) recurring series whose `nextExpectedAt`
     * falls inside `[period->start, period->endExclusive)`, soonest-first.
     * Series with no `nextExpectedAt` (irregular cadence with no anchor,
     * D-30/Pitfall-4 precedent from Calendar's own irregular-series gate)
     * are excluded — there is no well-defined "upcoming" date for them.
     *
     * @return list<RecurringSeriesDto>
     */
    private function upcomingRecurringCharges(User $user, Period $period): array
    {
        $series = $this->recurringSeries->allApprovedForUser($user);

        $upcoming = array_values(array_filter(
            $series,
            static fn (RecurringSeriesDto $row): bool => $row->nextExpectedAt !== null
                && ! $row->nextExpectedAt->lessThan($period->start)
                && $row->nextExpectedAt->lessThan($period->endExclusive),
        ));

        usort(
            $upcoming,
            static fn (RecurringSeriesDto $a, RecurringSeriesDto $b): int => $a->nextExpectedAt <=> $b->nextExpectedAt,
        );

        return $upcoming;
    }
}
