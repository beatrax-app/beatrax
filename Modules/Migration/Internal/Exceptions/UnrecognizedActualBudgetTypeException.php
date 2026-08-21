<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// preferences.budgetType is present but is neither an envelope/rollover nor a
// tracking/report token, so the reader cannot pick a mode.
final class UnrecognizedActualBudgetTypeException extends RuntimeException {}
