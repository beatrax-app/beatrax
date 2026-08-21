<?php

declare(strict_types=1);

namespace Modules\Shell\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;

final class SpendingTrendCard extends Component
{
    public function render(CurrentUser $currentUser, CategorySpendTrendQuery $query, ViewFactory $views): View
    {
        return $views->make('shell::livewire.spending-trend-card', [
            'trend' => $query->forUser($currentUser->user()),
        ]);
    }
}
