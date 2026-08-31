<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

// SQLite gives LIKE no escape character unless the predicate names one, so an
// escaped pattern sent without the ESCAPE clause does not neutralise the
// wildcard — it adds a literal backslash the reader never typed. Pattern and
// clause are built in the same call here so the two cannot drift apart again.
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

    public static function startsWithAnyCase(Builder $query, string $column, string $needle): void
    {
        $query->whereRaw(self::foldedPredicate($column), [self::escape($needle).'%']);
    }

    public static function orStartsWithAnyCase(Builder $query, string $column, string $needle): void
    {
        $query->orWhereRaw(self::foldedPredicate($column), [self::escape($needle).'%']);
    }

    /**
     * @return literal-string
     */
    private static function predicate(string $column): string
    {
        return self::column($column)." LIKE ? ESCAPE '".self::ESCAPE_CHARACTER."'";
    }

    /**
     * @return literal-string
     */
    private static function foldedPredicate(string $column): string
    {
        return 'LOWER('.self::column($column).") LIKE LOWER(?) ESCAPE '".self::ESCAPE_CHARACTER."'";
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
            default => throw new InvalidArgumentException("Unknown LIKE column: {$column}"),
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
