<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Exceptions;

use Modules\Goals\Public\Exceptions\InvalidGoalTargetDateException;

// A date the calendar has and this goal cannot use, because the goal starts
// after it. Folded into its parent it was refused as "Choose a real date."
/**
 * @link ../../../../.docs/features/goals/target-date-refusals.md
 */
final class GoalTargetDateBeforeStartException extends InvalidGoalTargetDateException {}
