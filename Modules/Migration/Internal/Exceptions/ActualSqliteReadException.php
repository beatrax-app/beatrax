<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// Thrown by ActualSqliteReader when the opened db.sqlite lacks a table
// or preferences row it needs, or a query/row comes back in a shape the
// reader cannot map — a structurally-wrong Actual export the reader
// refuses to read partially rather than returning half a budget.
/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class ActualSqliteReadException extends RuntimeException {}
