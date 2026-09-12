<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../.docs/architecture/a-rebuilt-table-loses-a-partial-index.md
 */

/**
 * @return list<string>
 */
function migrationSourceFiles(): array
{
    return array_merge(
        glob(UserDataPathService::modulesPath().'/*/Database/Migrations/*.php') ?: [],
        glob(UserDataPathService::migrationsPath().'/*.php') ?: [],
    );
}

// SQLite has no ALTER TABLE ADD CONSTRAINT, so Laravel adds a foreign key by
// rebuilding the table and regenerating each index from PRAGMA index_list,
// which reports columns and uniqueness and not the WHERE clause. A partial
// index comes back plain, under its own name, and nothing says so.
/**
 * @return array<string, string> index name => the predicate its migration declared
 */
function partialIndexesDeclaredInMigrations(): array
{
    $declared = [];

    foreach (migrationSourceFiles() as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            continue;
        }

        // Every one of these is written as concatenated PHP literals, so the
        // joins are closed up first and the statement read as one string.
        $sql = preg_replace('/[\'"][\s]*\.[\s]*[\'"]/', '', $source) ?? $source;

        $found = preg_match_all(
            '/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([A-Za-z0-9_]+)\s+ON\s+[A-Za-z0-9_]+\s*\([^)]*\)\s*WHERE\s+([^\r\n]*)/i',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        if ($found === false) {
            continue;
        }

        foreach ($matches as $match) {
            $declared[$match[1]] = rtrim(trim($match[2]), '\'";');
        }
    }

    return $declared;
}

/**
 * @return list<array{table: string, key: string, names: list<string>}>
 */
function indexesSharingOneKey(DatabaseManager $db): array
{
    $connection = $db->connection();

    $tables = array_map(
        static fn (object $row): string => (string) $row->name,
        $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
    );

    $shared = [];
    foreach ($tables as $table) {
        $byKey = [];
        foreach ($connection->select('SELECT name, partial FROM pragma_index_list(?)', [$table]) as $index) {
            $name = (string) $index->name;
            $columns = implode(',', array_map(
                static fn (object $column): string => (string) $column->name,
                $connection->select('SELECT name FROM pragma_index_info(?)', [$name]),
            ));
            $byKey[$columns.' partial='.$index->partial][] = $name;
        }

        foreach ($byKey as $key => $names) {
            if (count($names) > 1) {
                $shared[] = ['table' => $table, 'key' => $key, 'names' => $names];
            }
        }
    }

    return $shared;
}

// A regex that quietly stops reading would pass the loop below on an empty
// set, so the five the schema has today are named.
it('reads every partial index the migrations declare', function (): void {
    expect(array_keys(partialIndexesDeclaredInMigrations()))->toContain(
        'categories_global_slug_uq',
        'community_merchant_mappings_global_pattern_uq',
        'relay_mailbox_pending_idx',
        'tax_tags_whole_tx_unique',
        'transactions_uncategorized_idx',
        'transactions_unpaired_transfer_idx',
    );
});

it('keeps the predicate on every index a migration declared as partial', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    foreach (partialIndexesDeclaredInMigrations() as $name => $predicate) {
        $row = $db->connection()->selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?",
            [$name],
        );

        expect($row)->not->toBeNull(sprintf('%s is declared in a migration and absent from the built schema', $name));

        $sql = is_object($row) && isset($row->sql) ? (string) $row->sql : '';
        expect(str_contains(strtoupper($sql), 'WHERE'))->toBeTrue(
            sprintf('%s was declared partial on `%s`; the built schema has it without a predicate', $name, $predicate)
        );
    }
});

// Two indexes with one key is what a dropped predicate leaves behind: the
// narrow index becomes a copy of the wide one already beside it, and the
// queries written for the narrow one silently read the whole table.
it('carries no two indexes on one table with the same key', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect(indexesSharingOneKey($db))->toBe([]);
});
