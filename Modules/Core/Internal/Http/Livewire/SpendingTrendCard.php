<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;

/**
 * Dashboard "This month vs last" card — the month-over-month spend comparison
 * (total delta + the categories that moved most), from CategorySpendTrendQuery.
 * Renders nothing until there is something to compare, so an empty/first month
 * adds no chrome.
 *
 * Service collaborators arrive as render() parameters (constructor injection is
 * banned on Livewire Component subclasses by phpstan-strict-rules).
 */
final class SpendingTrendCard extends Component
{
    public function render(CurrentUser $currentUser, CategorySpendTrendQuery $query, ViewFactory $views): View
    {
        return $views->make('core::livewire.spending-trend-card', [
            'trend' => $query->forUser($currentUser->user()),
        ]);
    }
}
