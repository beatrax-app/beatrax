<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Exceptions;

use InvalidArgumentException;

/**
 * @see GoalNotFoundException
 */
final class InvalidGoalAmountException extends InvalidArgumentException {}
