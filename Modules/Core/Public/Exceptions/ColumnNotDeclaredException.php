<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// A column the query names is not on the table, because the migration that adds
// it has not run here yet. Raised rather than worked around: SQLite answers a
// predicate on an absent column with a string literal instead of an error, so
// the caller would otherwise act confidently on a clause that never applied.
/**
 * @link ../../../../.docs/conventions/a-predicate-on-a-column-that-is-not-there.md
 */
final class ColumnNotDeclaredException extends RuntimeException
{
    /**
     * @param  list<string>  $columns
     */
    public static function on(string $table, array $columns): self
    {
        return new self(sprintf(
            '%s is missing %s; run php artisan migrate',
            $table,
            implode(', ', $columns),
        ));
    }
}
