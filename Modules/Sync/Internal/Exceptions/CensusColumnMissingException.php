<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// A column the census reads is not on the table yet, because the migration that
// adds it has not run. Raised rather than worked around: SQLite answers a
// predicate on an absent column with a string literal instead of an error, so a
// census that carried on would report confidently and wrongly.
/**
 * @link ../../../../.docs/features/sync/architecture.md#a-predicate-on-a-column-the-migration-has-not-added
 */
final class CensusColumnMissingException extends RuntimeException
{
    /**
     * @param  list<string>  $columns
     */
    public static function of(string $table, array $columns): self
    {
        return new self(sprintf(
            'cannot classify — %s is missing %s; run php artisan migrate',
            $table,
            implode(', ', $columns),
        ));
    }
}
