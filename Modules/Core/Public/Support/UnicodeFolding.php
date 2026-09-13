<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Database\Connection;
use Modules\Core\Internal\Exceptions\UnicodeFoldingUnavailableException;
use Pdo\Sqlite;

// SQLite's `LIKE` and `LOWER()` fold ASCII and nothing else, and no pragma
// changes that. This is the one definition of "ignore case": the PHP fold
// below IS the SQL function, registered per connection because a SQLite user
// function is connection-scoped and nothing else in SQL can be.
/**
 * @link ../../../../.docs/architecture/case-folding-is-one-function.md
 */
final class UnicodeFolding
{
    // Prefixed, because the name shares a namespace with SQLite's own builtins
    // and with anything an extension registers.
    public const string SQL_FUNCTION = 'beatrax_fold';

    public static function of(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * @param  literal-string  $expression
     * @return literal-string
     */
    public static function sql(string $expression): string
    {
        return self::SQL_FUNCTION.'('.$expression.')';
    }

    // Deterministic, so SQLite may hoist the call out of a loop and use it in a
    // partial index; the fold depends on nothing but its argument.
    public static function registerOn(Connection $connection): void
    {
        $pdo = $connection->getPdo();

        // Refused as the connection opens rather than at the first query: a PDO
        // that cannot carry the function fails every folded search afterwards,
        // and a reader typing into the search box is the wrong place to find
        // out that this process opened the database through something else.
        if (! $pdo instanceof Sqlite) {
            throw UnicodeFoldingUnavailableException::notASqlitePdo(
                $connection->getName() ?? '',
                $pdo::class,
                self::SQL_FUNCTION,
            );
        }

        if (! $pdo->createFunction(self::SQL_FUNCTION, self::fold(...), 1, Sqlite::DETERMINISTIC)) {
            throw UnicodeFoldingUnavailableException::driverRefused(
                $connection->getName() ?? '',
                self::SQL_FUNCTION,
            );
        }
    }

    // SQLite hands a column's own affinity through, so a folded integer arrives
    // as an int and a folded NULL as null; neither is a string to lower.
    private static function fold(mixed $value): ?string
    {
        return is_scalar($value) ? self::of((string) $value) : null;
    }
}
