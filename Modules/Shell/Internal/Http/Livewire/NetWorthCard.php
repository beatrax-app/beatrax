<?php

declare(strict_types=1);

namespace Modules\Shell\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Public\Services\NetWorthQuery;

final class NetWorthCard extends Component
{
    public bool $expanded = false;

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
    }

    public function render(CurrentUser $currentUser, NetWorthQuery $query, ViewFactory $views): View
    {
        return $views->make('shell::livewire.net-worth-card', [
            'netWorth' => $query->forUser($currentUser->user()),
        ]);
    }
}
