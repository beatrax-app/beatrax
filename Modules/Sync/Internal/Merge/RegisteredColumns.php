<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;

// Per-table column allow-list backed by the live schema. The table name is
// already gated by MergeRulesRegistry; this is its field-name companion, so a
// SET/CREATE naming a column the table does not actually have is quarantined
// before it reaches a query-builder update()/insert() and fails at the DB.
final class RegisteredColumns
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function __construct(private readonly DatabaseManager $db) {}

    // True when $field is a real column of $table. A table with no known
    // columns on this connection (an optional module not migrated here) cannot
    // be judged, so it defers to the applier's own DB-error quarantine rather
    // than reject a field it has no schema to check against.
    public function isRegistered(string $table, string $field): bool
    {
        $columns = $this->columnsFor($table);

        return $columns === [] || in_array($field, $columns, true);
    }

    // Cached per table for the lifetime of one replay (a fresh instance is
    // built per OpLogReplayer), so the schema is read at most once per table.
    /**
     * @return list<string>
     */
    private function columnsFor(string $table): array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $schema = $this->db->connection()->getSchemaBuilder();

        return $this->cache[$table] = $schema->hasTable($table)
            ? $schema->getColumnListing($table)
            : [];
    }
}
