<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Inline dashboard "Unusual charges" count tile (D-03). Reads through
 * `AnomalyAlertQuery::openCountForUser` + `openDetectorBreakdownForUser`
 * for the current user; renders no chrome when `openCount === 0` so the
 * dashboard collapses gracefully on a quiet day — distinct from the drift
 * tile so subscription-creep and one-off unusual charges stay legible.
 *
 * Method-parameter DI on `render()` — constructor injection is banned on
 * Livewire `Component` subclasses by phpstan-strict-rules.
 */
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
