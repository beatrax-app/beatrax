<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Exceptions;

use InvalidArgumentException;

// A string that is not a calendar date at all. Not final: the narrower
// GoalTargetDateBeforeStartException extends it, so a caller wanting either
// refusal still catches this one.
/**
 * @link ../../../../.docs/features/goals/target-date-refusals.md
 */
class InvalidGoalTargetDateException extends InvalidArgumentException {}
