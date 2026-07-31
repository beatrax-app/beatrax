<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// Thrown by ActualSqliteReader::budgetType when the source export's
// preferences.budgetType value is present but not one of the known
// envelope/rollover or tracking/report tokens — an Actual schema the
// reader cannot map to envelope-vs-tracking mode.
/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class UnrecognizedActualBudgetTypeException extends RuntimeException {}
