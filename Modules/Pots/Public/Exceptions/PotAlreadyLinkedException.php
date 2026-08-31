<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

use InvalidArgumentException;

// The mirror of GoalAlreadyLinkedException, and the direction that shipped
// unguarded: a goal write could hand itself a pot another goal already held,
// which re-pointed the pot and left the first goal reading 0% with no pot.
final class PotAlreadyLinkedException extends InvalidArgumentException {}
