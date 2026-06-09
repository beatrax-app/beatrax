<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by PotWriter when a pot cannot be resolved for the acting user —
 * a missing id or a cross-user attempt. Distinct exception type so callers
 * drive control flow on exception identity, not message text (WR-05).
 *
 * Extends InvalidArgumentException so existing broad `catch` sites keep working.
 */
final class PotNotFoundException extends InvalidArgumentException {}
