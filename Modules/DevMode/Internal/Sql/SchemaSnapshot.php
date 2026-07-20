<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Illuminate\Database\DatabaseManager;
use Throwable;

// Uses Laravel's native Schema API (getTables/getColumns/getIndexes/
// getForeignKeys) rather than raw PRAGMA queries, so the enumeration
// stays portable across drivers even though the read-only sibling
// connection this reads from is sqlite-only.
final readonly class SchemaSnapshot
{
    public function __construct(private DatabaseManager $db) {}

    // Row count uses the raw query builder on the default connection
    // (not the read-only sibling), since the schema API itself has no
    // count primitive.
    /**
     * @return list<array{
     *   name: string,
     *   columns: list<array<string, mixed>>,
     *   indexes: list<array<string, mixed>>,
     *   foreign_keys: list<array<string, mixed>>,
     *   row_count: int|null,
     * }>
     */
    public function all(): array
    {
        $schema = $this->db->connection()->getSchemaBuilder();
        $tables = $schema->getTables();

        $snapshot = [];
        foreach ($tables as $table) {
            $name = $table['name'];
            if ($name === '') {
                continue;
            }
            // SQLite's getTables() includes the internal sqlite_*
            // tables and the migrations table. Hide the sqlite_*
            // tables (operator-internal noise); keep migrations.
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $rowCount = null;
            try {
                $rowCount = $this->db->connection()->table($name)->count();
            } catch (Throwable) {
                $rowCount = null;
            }

            $snapshot[] = [
                'name' => $name,
                'columns' => $schema->getColumns($name),
                'indexes' => $schema->getIndexes($name),
                'foreign_keys' => $schema->getForeignKeys($name),
                'row_count' => $rowCount,
            ];
        }

        return $snapshot;
    }
}
