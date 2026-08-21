<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// A structurally-wrong Actual export: the reader refuses it whole rather than
// returning half a budget.
final class ActualSqliteReadException extends RuntimeException {}
