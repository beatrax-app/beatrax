<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Public\Contracts\CurrentUser;

final class DashboardAnomalyBadge extends Component
{
    public function render(
        CurrentUser $currentUser,
        AnomalyAlertQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $openCount = $query->openCountForUser($user);
        $breakdown = $query->openDetectorBreakdownForUser($user);

        return $views->make('anomaly::livewire.dashboard-anomaly-badge', [
            'openCount' => $openCount,
            'breakdown' => $breakdown,
        ]);
    }
}
