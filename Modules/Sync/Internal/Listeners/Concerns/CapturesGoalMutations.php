<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners\Concerns;

use Modules\Sync\Internal\OpLog\OpCaptureSink;
use Modules\Sync\Public\Events\GoalContributionMutated;
use Modules\Sync\Public\Events\GoalMutated;

trait CapturesGoalMutations
{
    private function handleGoalContributionDelete(GoalContributionMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeDelete(
            table: 'goal_contributions',
            pk: $event->contributionId,
        );
    }

    private function handleGoalContributionCreate(GoalContributionMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeCreateRow(
            table: 'goal_contributions',
            pk: $event->contributionId,
            fields: $event->dirtyFields,
        );
    }

    private function handleGoalCreate(GoalMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeCreateRow(
            table: 'goals',
            pk: $event->goalId,
            fields: $event->dirtyFields,
        );
    }

    // An edit writes one op per touched column rather than a whole row, so
    // two devices renaming and re-dating the same goal both keep their change
    // instead of the later write replacing the earlier wholesale.
    private function handleGoalEdit(GoalMutated $event, OpCaptureSink $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'goals',
                pk: $event->goalId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleGoalDelete(GoalMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeDelete(
            table: 'goals',
            pk: $event->goalId,
        );
    }
}
