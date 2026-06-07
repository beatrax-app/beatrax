<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;

/**
 * Dashboard "Ways to save" card — renders the SavingsInsightsQuery suggestions
 * (cheaper plan / cancel after a price rise / review an ongoing charge), each
 * with the official corpus link and a dismiss control. Renders nothing when
 * there is nothing to suggest, so it never adds empty chrome to the dashboard.
 *
 * Service collaborators arrive as render()/action parameters — constructor
 * injection is banned on Livewire Component subclasses by phpstan-strict-rules.
 */
final class SavingsInsightsCard extends Component
{
    public function dismiss(string $key, CurrentUser $currentUser, SavingsInsightsQuery $query): void
    {
        $query->dismiss($currentUser->user(), $key);
    }

    public function render(CurrentUser $currentUser, SavingsInsightsQuery $query, ViewFactory $views): View
    {
        return $views->make('drift-alerts::livewire.savings-insights-card', [
            'insights' => $query->forUser($currentUser->user()),
        ]);
    }
}
