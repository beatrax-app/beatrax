<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

// The other half of a create the transport split. Nothing keeps one row's
// create ops inside a single frame, so the half that lands second names a row
// this device already holds. Losing it cost a first sync 8 counterparty links
// and 7 payment types, with no quarantine row and matching row counts.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SplitCreateTail
{
    private const array WRITTEN_FROM_THE_ENVELOPE_NOT_THE_WIRE = ['id', 'user_id', 'created_at', 'updated_at'];

    /** @var array<string, array<string, mixed>> */
    private array $defaults = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RowOwnership $ownership,
    ) {}

    // Fills only the columns the stored row never received — still holding the
    // schema default, or null. A column carrying anything else was written by
    // somebody, and a replayed create must not talk over an edit that followed
    // it.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function fill(string $table, int|string $pk, array $payload, int $userId): void
    {
        $stored = $this->storedRow($table, $pk);

        if ($stored === null) {
            return;
        }

        $absent = [];

        foreach ($payload as $column => $value) {
            if ($value === null || in_array($column, self::WRITTEN_FROM_THE_ENVELOPE_NOT_THE_WIRE, true)) {
                continue;
            }

            if ($this->neverWritten($table, $column, $stored)) {
                $absent[$column] = $value;
            }
        }

        if ($absent === []) {
            return;
        }

        $this->write($table, $pk, $absent, $userId);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function neverWritten(string $table, string $column, array $stored): bool
    {
        if (! array_key_exists($column, $stored)) {
            return false;
        }

        $here = $stored[$column];

        if ($here === null) {
            return true;
        }

        $default = $this->defaultsFor($table)[$column] ?? null;

        return $default !== null && self::asText($here) === self::asText($default);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(string $table, int|string $pk, array $values, int $userId): void
    {
        $query = $this->db->connection()->table($table)->where('id', $pk);

        try {
            $this->ownership->scopeToUser($query, $table, $userId)->update($values);
        } catch (QueryException) {
            // A foreign key whose target has not landed yet, which the next
            // half of the same history brings. The row is already applied and
            // usable, so this costs the column and never the replay.
        }
    }

    // Cached per table for the lifetime of one replay, the same bargain
    // RegisteredColumns makes: the schema is read at most once per table.
    /**
     * @return array<string, mixed>
     */
    private function defaultsFor(string $table): array
    {
        if (isset($this->defaults[$table])) {
            return $this->defaults[$table];
        }

        $schema = $this->db->connection()->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return $this->defaults[$table] = [];
        }

        $defaults = [];

        foreach ($schema->getColumns($table) as $column) {
            $defaults[$column['name']] = $column['default'];
        }

        return $this->defaults[$table] = $defaults;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedRow(string $table, int|string $pk): ?array
    {
        try {
            $row = $this->db->connection()->table($table)->where('id', $pk)->first();
        } catch (\Throwable) {
            return null;
        }

        if (! is_object($row)) {
            return null;
        }

        $columns = [];

        foreach (get_object_vars($row) as $column => $value) {
            $columns[(string) $column] = $value;
        }

        return $columns;
    }

    // SQLite hands a schema default back as its literal text, quotes and all,
    // while the stored value comes back typed; comparing both as trimmed text
    // is what makes 'unknown' and "'unknown'" the same answer.
    private static function asText(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => trim((string) $value, "'"),
            default => '',
        };
    }
}
