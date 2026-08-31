<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Database\ConnectionInterface;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;

// The one shape the rule takes at every call site: ask the table for the id of
// the row these columns identify. Shared so the sites cannot drift into
// disagreeing about what a missing answer means.
/**
 * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
 */
final class IdReadBack
{
    /**
     * @param  array<string, mixed>  $match  the columns the table's UNIQUE names
     */
    public static function orNull(ConnectionInterface $connection, string $table, array $match): ?int
    {
        $id = $connection->table($table)->where($match)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $match  the columns the table's UNIQUE names
     *
     * @throws IdReadBackFailedException
     */
    public static function of(ConnectionInterface $connection, string $table, array $match): int
    {
        return self::orNull($connection, $table, $match) ?? throw new IdReadBackFailedException($table);
    }
}
