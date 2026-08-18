<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners\Concerns;

use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\GoalContributionMutated;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
trait CapturesGoalMutations
{
    private function handleGoalContributionDelete(GoalContributionMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'goal_contributions',
            pk: $event->contributionId,
        );
    }

    private function handleGoalContributionCreate(GoalContributionMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'goal_contributions',
            pk: $event->contributionId,
            fields: $event->dirtyFields,
        );
    }
}
