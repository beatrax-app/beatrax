<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by GoalWriter when a goal cannot be resolved for the acting user —
 * a missing id or a cross-user attempt. Distinct from InvalidGoalAmountException
 * so callers drive control flow on exception identity rather than sniffing
 * message text (WR-05).
 *
 * Extends InvalidArgumentException so existing broad `catch` sites keep working.
 */
final class GoalNotFoundException extends InvalidArgumentException {}
