<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Exceptions;

use InvalidArgumentException;

/**
 * @see InvalidGoalAmountException
 */
final class GoalNotFoundException extends InvalidArgumentException {}
