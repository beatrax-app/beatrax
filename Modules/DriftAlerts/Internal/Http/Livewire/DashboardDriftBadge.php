<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;

/**
 * Inline dashboard "Drift alerts" count tile. Reads through
 * `DriftAlertQuery::openCountForUser` + `totalOpenAnnualizedImpactForUser`
 * for the current user; renders no chrome when `openCount === 0` so the
 * dashboard collapses gracefully on a quiet day.
 *
 * Method-parameter DI on `render()` — constructor injection is banned
 * on Livewire `Component` subclasses by phpstan-strict-rules.
 */
final class DashboardDriftBadge extends Component
{
    public function render(
        CurrentUser $currentUser,
        DriftAlertQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $openCount = $query->openCountForUser($user);
        $totalAnnualizedImpact = $query->totalOpenAnnualizedImpactForUser($user);

        return $views->make('drift-alerts::livewire.dashboard-drift-badge', [
            'openCount' => $openCount,
            'totalAnnualizedImpact' => $totalAnnualizedImpact,
        ]);
    }
}
