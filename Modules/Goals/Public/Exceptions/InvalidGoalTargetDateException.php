<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Exceptions;

use InvalidArgumentException;

// Carbon normalises "2026-02-30" to "2026-03-02", so a stored target date that
// was never a date round-trips as a different one. The column held whatever the
// form sent, and every reader of it -- the projection, the card, the sort --
// then worked from a date the goal's owner never chose.
final class InvalidGoalTargetDateException extends InvalidArgumentException {}
