<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

final class FixedPaymentsCard extends Component
{
    #[Url(as: 'fp-filter')]
    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'this-month'], true) ? $filter : 'all';
    }

    public function render(
        CurrentUser $currentUser,
        FixedPaymentsViewQuery $query,
        ViewFactory $views,
        Clock $clock,
    ): View {
        $user = $currentUser->user();

        // The date filter goes into the query so the limit clips the matching set
        // rather than the unfiltered population — otherwise a card with rows to
        // show would surface the "no series" empty state.
        $monthStart = null;
        $monthEnd = null;
        if ($this->filter === 'this-month') {
            $monthStart = $clock->now()->startOfMonth();
            $monthEnd = $clock->now()->endOfMonth();
        }

        $rows = $query->topByMonthlyEquivalent($user, 6, $monthStart, $monthEnd);
        $totals = $query->monthlyEquivalentTotals($user);

        return $views->make('recurring::livewire.fixed-payments-card', [
            'rows' => $rows,
            'totals' => $totals,
            'filter' => $this->filter,
        ]);
    }
}
