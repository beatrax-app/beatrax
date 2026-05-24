<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Throwable;

/**
 * Read-only schema enumeration for the `/dev/sql` schema viewer
 * (CONTEXT D-47 + RESEARCH § Pattern 8).
 *
 * Uses Laravel 11+'s native Schema API
 * ({@see Builder::getTables()} +
 * {@see Builder::getColumns()} +
 * {@see Builder::getIndexes()} +
 * {@see Builder::getForeignKeys()}) — no
 * raw PRAGMA queries, cross-DB-ready (RESEARCH Q2 documents the
 * SQLite-only assumption: the read-only connection IS sqlite-only,
 * but the schema enumeration itself is portable).
 *
 * Row count uses the raw query builder (the table is enumerated via
 * the schema API which has no count primitive); the count flows
 * through the same DatabaseManager so reads stay on the default
 * connection (NOT through the read-only sibling — counts are reads
 * but the default connection has no PRAGMA query_only restriction so
 * a future cross-aggregation would still work).
 */
final readonly class SchemaSnapshot
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * Snapshot of every table the configured connection sees.
     *
     * Each entry exposes the Laravel-native getColumns / getIndexes /
     * getForeignKeys arrays plus a best-effort row count (null when a
     * count read fails — e.g. system tables the count primitive
     * cannot read).
     *
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
