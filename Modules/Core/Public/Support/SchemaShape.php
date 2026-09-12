<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Database\Connection;

// A row in `migrations` records that a statement once ran. It is not evidence
// the schema still carries what that statement wrote, and an install has been
// found where it did not. Both facts below are read back out of sqlite_master,
// so a drifted database answers for itself instead of for its migration rows.
/**
 * @link ../../../../.docs/features/core/a-schema-the-migrations-table-vouched-for.md
 */
final class SchemaShape
{
    public const string CASCADING_TABLES = <<<'SQL'
        select name from sqlite_master
         where type = 'table' and sql like '%on delete cascade%'
         order by name
        SQL;

    // The strip the shipped removal migration performs, spelled once here
    // because that migration is shipped and therefore append-only: a second
    // caller needing the identical statement cannot reach inside it for it.
    public const string STRIP_CASCADES = <<<'SQL'
        update sqlite_master set sql = replace(sql, ' on delete cascade', '')
         where type = 'table' and sql like '%on delete cascade%'
        SQL;

    private const string GUARDED_TABLE = 'users';

    private const string TRIGGERS_ON_GUARDED_TABLE = <<<'SQL'
        select name from sqlite_master where type = 'trigger' and tbl_name = ?
        SQL;

    private const string GUARDED_TABLE_EXISTS = <<<'SQL'
        select count(*) as n from sqlite_master where type = 'table' and name = ?
        SQL;

    // Definitions, not just names: a SQLite table rebuild keeps the columns and
    // the indices and silently drops every trigger, so whatever notices has to
    // be able to put them back. Held against a freshly migrated schema by test,
    // which is what stops this going stale where nobody would read it.
    /** @var array<string, string> */
    private const array ENUM_GUARD_TRIGGERS = [
        'users_receipt_conflict_resolution_check_insert' => <<<'SQL'
            CREATE TRIGGER users_receipt_conflict_resolution_check_insert BEFORE INSERT ON users FOR EACH ROW
                         WHEN NEW.receipt_conflict_resolution NOT IN ('unset','prefer_receipt','prefer_first_write')
                         BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END
            SQL,
        'users_receipt_conflict_resolution_check_update' => <<<'SQL'
            CREATE TRIGGER users_receipt_conflict_resolution_check_update BEFORE UPDATE OF receipt_conflict_resolution ON users FOR EACH ROW
                         WHEN NEW.receipt_conflict_resolution NOT IN ('unset','prefer_receipt','prefer_first_write')
                         BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END
            SQL,
    ];

    /** @return list<string> */
    public static function cascadingTables(Connection $connection): array
    {
        return self::names($connection, self::CASCADING_TABLES);
    }

    // Silent where the guarded table is absent, which is not drift: a fresh
    // install opens connections all through its first migrate, every one of
    // them before the table this asks about exists.
    /** @return list<string> */
    public static function missingTriggers(Connection $connection): array
    {
        if (! self::guardedTableExists($connection)) {
            return [];
        }

        $present = self::names($connection, self::TRIGGERS_ON_GUARDED_TABLE, [self::GUARDED_TABLE]);

        return array_values(array_diff(array_keys(self::ENUM_GUARD_TRIGGERS), $present));
    }

    /** @return array<string, string> */
    public static function enumGuardTriggers(): array
    {
        return self::ENUM_GUARD_TRIGGERS;
    }

    private static function guardedTableExists(Connection $connection): bool
    {
        $row = $connection->selectOne(self::GUARDED_TABLE_EXISTS, [self::GUARDED_TABLE]);
        $count = is_object($row) ? ($row->n ?? null) : null;

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string>
     */
    private static function names(Connection $connection, string $sql, array $bindings = []): array
    {
        $names = [];

        foreach ($connection->select($sql, $bindings) as $row) {
            $name = is_object($row) ? ($row->name ?? null) : null;

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
