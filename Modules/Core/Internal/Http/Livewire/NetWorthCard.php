<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Public\Services\NetWorthQuery;

/**
 * Dashboard net-worth card — one EUR figure across all accounts (assets minus
 * liabilities) with the per-account breakdown, from NetWorthQuery. Renders
 * nothing until there is at least one account.
 *
 * Service collaborators arrive as render() parameters (constructor injection is
 * banned on Livewire Component subclasses by phpstan-strict-rules).
 */
final class NetWorthCard extends Component
{
    public bool $expanded = false;

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
    }

    public function render(CurrentUser $currentUser, NetWorthQuery $query, ViewFactory $views): View
    {
        return $views->make('core::livewire.net-worth-card', [
            'netWorth' => $query->forUser($currentUser->user()),
        ]);
    }
}
