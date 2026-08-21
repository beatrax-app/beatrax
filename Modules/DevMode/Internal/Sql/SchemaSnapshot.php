<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Illuminate\Database\DatabaseManager;
use Throwable;

// Laravel's Schema API rather than raw PRAGMA queries, so the enumeration
// survives a driver change even though today's connection is sqlite-only.
final readonly class SchemaSnapshot
{
    public function __construct(private DatabaseManager $db) {}

    // The schema API has no count primitive, so the row count drops to the
    // query builder on the default connection.
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
            // SQLite lists its internal tables alongside the real ones; the
            // migrations table is deliberately kept.
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
