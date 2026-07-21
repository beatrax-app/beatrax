<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Goals\Public\Services\GoalProgressQuery;

/**
 * @link ../../../../../.docs/features/goals/architecture.md
 */
final class GoalsSummaryCard extends Component
{
    public function render(
        CurrentUser $currentUser,
        GoalProgressQuery $query,
        ViewFactory $views,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            return $views->make('goals::livewire.goals-summary-card', ['goals' => []]);
        }

        $rows = $query->forUser($currentUser->user());

        // Sort by projected finish date (soonest first), then by target date as
        // a fallback when there is no projection (null sorts to the end).
        usort($rows, static function (GoalProgressRow $a, GoalProgressRow $b): int {
            if ($a->projectedFinishDate === null && $b->projectedFinishDate === null) {
                return 0;
            }
            if ($a->projectedFinishDate === null) {
                return 1;
            }
            if ($b->projectedFinishDate === null) {
                return -1;
            }

            return strcmp($a->projectedFinishDate, $b->projectedFinishDate);
        });

        $top3 = array_slice($rows, 0, 3);

        return $views->make('goals::livewire.goals-summary-card', ['goals' => $top3]);
    }
}
