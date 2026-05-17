<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

/**
 * Inline dashboard "Fixed monthly payments" card. Sources its row set
 * from `FixedPaymentsViewQuery::topByMonthlyEquivalent` (the top six
 * approved series by absolute monthly equivalent across both expense
 * and income directions) and ships an "All series / This month only"
 * filter persisted via `#[Url(as: 'fp-filter')]` so the user's choice
 * survives page refresh and is shareable.
 *
 * Method-parameter DI on every action and on `render()` —
 * constructor injection is banned on Livewire Component subclasses by
 * phpstan-strict-rules.
 */
final class FixedPaymentsCard extends Component
{
    /**
     * `all` (default) or `this-month` — the dashboard tile filter.
     * Persisted as a query-string variable so the user's choice
     * survives reloads + is shareable via URL.
     */
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
        $rows = $query->topByMonthlyEquivalent($user, 6);
        $totals = $query->monthlyEquivalentTotals($user);

        if ($this->filter === 'this-month') {
            $startOfMonth = $clock->now()->startOfMonth();
            $endOfMonth = $clock->now()->endOfMonth();
            $rows = array_values(array_filter(
                $rows,
                static function (RecurringSeriesDto $row) use ($startOfMonth, $endOfMonth): bool {
                    if ($row->nextExpectedAt === null) {
                        return false;
                    }

                    return $row->nextExpectedAt->between($startOfMonth, $endOfMonth);
                },
            ));
        }

        return $views->make('recurring::livewire.fixed-payments-card', [
            'rows' => $rows,
            'totals' => $totals,
            'filter' => $this->filter,
        ]);
    }
}
