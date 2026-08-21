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

        // Mirrors the dashboard's toggle byte for byte, which is what makes a
        // later seam-swap a pure no-op.
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
