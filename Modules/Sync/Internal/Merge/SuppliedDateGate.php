<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeDate;

// A peer's word for a DATE column, read before it is written: the applier was
// the one place a supplied date went through unread, so '2027-02-29' reached
// the column and only the model cast objected, on the way back OUT. Which
// columns are DATE is asked of the schema, because a pin goes stale in silence.
final class SuppliedDateGate
{
    /** @var array<string, list<string>> */
    private array $dateColumns = [];

    public function __construct(private readonly DatabaseManager $db) {}

    public function refuses(string $table, string $field, mixed $value): bool
    {
        // Emptiness is the column's own business — nullable is a schema
        // question and this gate only reads days.
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        if (! in_array($field, $this->dateColumnsFor($table), true)) {
            return false;
        }

        // dayOrNull, never normalisedDayOrNull: this is the supplying side, so
        // a value that is not exactly the day somebody meant is refused rather
        // than rolled forward into one they did not.
        return SafeDate::dayOrNull($value) === null;
    }

    /**
     * @return list<string>
     */
    private function dateColumnsFor(string $table): array
    {
        return $this->dateColumns[$table] ??= $this->readDateColumns($table);
    }

    /**
     * @return list<string>
     */
    private function readDateColumns(string $table): array
    {
        $schema = $this->db->connection()->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return [];
        }

        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            if ($column['type_name'] === 'date') {
                $columns[] = $column['name'];
            }
        }

        return $columns;
    }
}
