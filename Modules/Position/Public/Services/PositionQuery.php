<?php

declare(strict_types=1);

namespace Modules\Position\Public\Services;

use Modules\Budgets\Public\Services\EnvelopeProgressQuery;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\CurrencyView;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Recurring\Public\Support\SeriesDueWindow;

final readonly class PositionQuery
{
    public function __construct(
        private ThisPeriodAtAGlanceQuery $glance,
        private EnvelopeProgressQuery $budgets,
        private RecurringSeriesQuery $recurringSeries,
        private ForecastHighlightsQuery $forecastHighlights,
        private NetWorthQuery $netWorth,
    ) {}

    public function forUser(User $user, Period $period): PositionSummaryDto
    {
        $summary = $this->glance->for($user, $period);

        // Mirrors the dashboard's toggle byte for byte, which is what makes a
        // later seam-swap a pure no-op.
        $tilesByCurrency = $user->default_currency_view === CurrencyView::Original
            ? $this->glance->forByCurrency($user, $period)
            : null;

        $emailScanHealth = $this->glance->emailScanHealth($user);

        return new PositionSummaryDto(
            summary: $summary,
            tilesByCurrency: $tilesByCurrency,
            emailScanHealth: $emailScanHealth,
            upcoming: $this->upcomingRecurringCharges($user, $period),
            budgets: $this->budgets->forPeriod($user, $period),
            shortfallRisk: $this->forecastHighlights->shortfallRiskForUser($user),
            // Today's holdings, not the period's: the roll-up answers "what do
            // you hold now", and paging the dashboard back a month must not
            // restate it as what you held then.
            netWorth: $this->netWorth->forUser($user),
        );
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    private function upcomingRecurringCharges(User $user, Period $period): array
    {
        $upcoming = SeriesDueWindow::dueWithin($this->recurringSeries->allApprovedForUser($user), $period);

        usort(
            $upcoming,
            static fn (RecurringSeriesDto $a, RecurringSeriesDto $b): int => $a->nextExpectedAt <=> $b->nextExpectedAt,
        );

        return $upcoming;
    }
}
