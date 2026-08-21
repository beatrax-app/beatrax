<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Goals\Public\Services\GoalProgressQuery;

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

        // Sort by projected finish date (soonest first). The leading
        // null-check flag pushes goals without a projection to the end; the
        // date string then breaks ties among goals that do have one.
        usort($rows, static function (GoalProgressRow $a, GoalProgressRow $b): int {
            return [$a->projectedFinishDate === null, (string) $a->projectedFinishDate]
                <=> [$b->projectedFinishDate === null, (string) $b->projectedFinishDate];
        });

        $top3 = array_slice($rows, 0, 3);

        return $views->make('goals::livewire.goals-summary-card', ['goals' => $top3]);
    }
}
