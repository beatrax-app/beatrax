<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by GoalWriter when a user-entered target amount is invalid, zero, or
 * negative. Distinct from GoalNotFoundException so callers drive control flow on
 * exception identity rather than sniffing message text (WR-05).
 *
 * Extends InvalidArgumentException so existing broad `catch` sites keep working.
 */
final class InvalidGoalAmountException extends InvalidArgumentException {}
