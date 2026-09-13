<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Modules\Core\Public\Support\UnicodeFolding;

// SQLite gives LIKE no escape character unless the predicate names one, so an
// escaped pattern sent without the ESCAPE clause does not neutralise the
// wildcard — it adds a literal backslash the reader never typed. Pattern and
// clause are built in the same call here so the two cannot drift apart again.
/**
 * @link ../../../../.docs/architecture/case-folding-is-one-function.md
 */
final class LikeNeedle
{
    private const string ESCAPE_CHARACTER = '\\';

    public static function contains(Builder $query, string $column, string $needle): void
    {
        $query->whereRaw(self::predicate($column), ['%'.self::escape($needle).'%']);
    }

    public static function orContains(Builder $query, string $column, string $needle): void
    {
        $query->orWhereRaw(self::predicate($column), ['%'.self::escape($needle).'%']);
    }

    public static function startsWith(Builder $query, string $column, string $needle): void
    {
        $query->whereRaw(self::predicate($column), [self::escape($needle).'%']);
    }

    public static function orStartsWith(Builder $query, string $column, string $needle): void
    {
        $query->orWhereRaw(self::predicate($column), [self::escape($needle).'%']);
    }

    // Both sides folded, by the same function the trigram tokenizer's own
    // case-insensitivity is matched against: SQLite's LIKE folds ASCII and its
    // LOWER() folds no more, so the arm a needle landed in used to decide
    // whether a non-ASCII capital in the body was reachable at all.
    /**
     * @return literal-string
     */
    private static function predicate(string $column): string
    {
        return UnicodeFolding::sql(self::column($column))
            .' LIKE '.UnicodeFolding::sql('?')
            ." ESCAPE '".self::ESCAPE_CHARACTER."'";
    }

    // whereRaw() needs a literal-string, so the column is matched against a
    // fixed set rather than interpolated or run through the grammar's wrap().
    // An unlisted column is a programming error, not a value to quote.
    /**
     * @return literal-string
     */
    private static function column(string $column): string
    {
        return match ($column) {
            'name' => 'name',
            'categories.name' => 'categories.name',
            'detected_name' => 'detected_name',
            'display_name_override' => 'display_name_override',
            'transaction_search_docs.search_body' => 'transaction_search_docs.search_body',
            default => throw new InvalidArgumentException(sprintf('Unknown LIKE column: %s', $column)),
        };
    }

    // The escape character itself goes first: escaping the wildcards before it
    // would then double the backslashes this pass had just introduced.
    private static function escape(string $needle): string
    {
        return str_replace(
            [self::ESCAPE_CHARACTER, '%', '_'],
            [self::ESCAPE_CHARACTER.self::ESCAPE_CHARACTER, self::ESCAPE_CHARACTER.'%', self::ESCAPE_CHARACTER.'_'],
            $needle,
        );
    }
}
