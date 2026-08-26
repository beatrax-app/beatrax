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
    private const int SLOTS = 3;

    public function render(
        CurrentUser $currentUser,
        GoalProgressQuery $query,
        ViewFactory $views,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            return $views->make('goals::livewire.goals-summary-card', ['goals' => []]);
        }

        // The query answers the /goals page, which lists closed goals under a
        // badge. This card has room for three and projects a finish date on
        // every one, so a closed goal here spends a slot on a finish that
        // already happened and costs a goal still being saved for.
        $rows = array_values(array_filter(
            $query->forUser($currentUser->user()),
            static fn (GoalProgressRow $row): bool => ! $row->isCompleted(),
        ));

        // Sort by projected finish date (soonest first). The leading
        // null-check flag pushes goals without a projection to the end; the
        // date string then breaks ties among goals that do have one.
        usort($rows, static function (GoalProgressRow $a, GoalProgressRow $b): int {
            return [$a->projectedFinishDate === null, (string) $a->projectedFinishDate]
                <=> [$b->projectedFinishDate === null, (string) $b->projectedFinishDate];
        });

        return $views->make('goals::livewire.goals-summary-card', [
            'goals' => array_slice($rows, 0, self::SLOTS),
        ]);
    }
}
