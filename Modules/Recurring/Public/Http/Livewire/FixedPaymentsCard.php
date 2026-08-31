<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Recurring\Internal\Enums\FixedPaymentsFilter;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

final class FixedPaymentsCard extends Component
{
    #[Url(as: 'fp-filter')]
    public string $filter = FixedPaymentsFilter::DEFAULT;

    public function setFilter(string $filter): void
    {
        $this->filter = (FixedPaymentsFilter::tryFrom($filter) ?? FixedPaymentsFilter::All)->value;
    }

    // The URL can carry anything, so the query the card runs is resolved from
    // the enum rather than trusted.
    private function activeFilter(): FixedPaymentsFilter
    {
        return FixedPaymentsFilter::tryFrom($this->filter) ?? FixedPaymentsFilter::All;
    }

    public function render(
        CurrentUser $currentUser,
        FixedPaymentsViewQuery $query,
        ViewFactory $views,
        PeriodQuery $periods,
    ): View {
        $user = $currentUser->user();

        // The date filter goes into the query so the limit clips the matching set
        // rather than the unfiltered population — otherwise a card with rows to
        // show would surface the "no series" empty state.

        // "This month" is the reader's own period, the window the upcoming list
        // directly above this card is drawn over. Taken as a calendar month
        // here, the two disagreed by 24 days at each end on start day 25.
        $filter = $this->activeFilter();
        $dueWithin = $filter === FixedPaymentsFilter::ThisMonth ? $periods->current() : null;

        $rows = $query->topByMonthlyEquivalent($user, 6, $dueWithin);
        $totals = $query->monthlyEquivalentTotals($user);

        return $views->make('recurring::livewire.fixed-payments-card', [
            'rows' => $rows,
            'totals' => $totals,
            'activeFilter' => $filter,
        ]);
    }
}
