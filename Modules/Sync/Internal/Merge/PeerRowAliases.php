<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Throwable;

// Which local row a peer's id means, when the two devices minted different ids
// for the same logical row. Two devices that signed up independently each seed
// their own reference data — tax deduction categories, for one — so the peer's
// create arrives as id 109 and collides with local row 13 on (user_id, name).
//
// The collision itself is harmless: the row IS already here. What was lost is
// the peer's identity for it, so all nineteen tax tags naming 109 failed their
// foreign key and were quarantined with nothing anywhere saying so.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class PeerRowAliases
{
    private const string TABLE = 'op_log_row_aliases';

    public function __construct(
        private DatabaseManager $db,
        private CoveredTableOrder $tableOrder,
    ) {}

    // Called when an insert was refused because the row is already present. If
    // a local row holds the payload's natural key under a DIFFERENT id, the two
    // ids name one row and the pair is remembered. A pk collision records
    // nothing — the ids already agree, which is the ordinary idempotent replay.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function remember(string $table, string $deviceId, int|string $remoteId, array $payload, int $userId): void
    {
        $localId = $this->localTwinOf($table, $payload);

        if ($localId === null || (string) $localId === (string) $remoteId) {
            return;
        }

        $this->db->connection()->table(self::TABLE)->insertOrIgnore([
            'user_id' => $userId,
            'table_name' => $table,
            'device_id' => $deviceId,
            'remote_id' => (string) $remoteId,
            'local_id' => (string) $localId,
            'created_at' => self::asText($payload['updated_at'] ?? $payload['created_at'] ?? ''),
        ]);
    }

    // The local id a peer's id stands for, or null when the two agree.
    public function localFor(string $table, string $deviceId, int|string $remoteId, int $userId): ?string
    {
        $row = $this->db->connection()->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('device_id', $deviceId)
            ->where('remote_id', (string) $remoteId)
            ->value('local_id');

        return is_string($row) && $row !== '' ? $row : null;
    }

    // Every foreign key in a payload rewritten to the id this device uses, read
    // off the live schema rather than a list, so a column added tomorrow is
    // covered without anyone remembering this class exists.
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function translate(string $table, string $deviceId, array $payload, int $userId): array
    {
        foreach ($this->parentColumns($table) as $column => $parent) {
            $value = $payload[$column] ?? null;

            if (! is_int($value) && ! is_string($value)) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $local = $this->localFor($parent, $deviceId, $value, $userId);

            if ($local !== null) {
                $payload[$column] = is_numeric($local) ? (int) $local : $local;
            }
        }

        return $payload;
    }

    // A schema read, so a table the registry does not cover answers empty
    // rather than raising into the middle of a replay.
    /**
     * @return array<string, string>
     */
    private function parentColumns(string $table): array
    {
        try {
            return $this->tableOrder->parentColumns($table);
        } catch (Throwable) {
            return [];
        }
    }

    // The local row holding the same natural key: every unique index the table
    // declares apart from the primary key, tried against the payload. Derived
    // from the schema so the answer follows the index, not a copy of it.
    /**
     * @param  array<string, mixed>  $payload
     */
    private function localTwinOf(string $table, array $payload): int|string|null
    {
        foreach ($this->uniqueIndexes($table) as $columns) {
            $query = $this->db->connection()->table($table);
            $usable = true;

            foreach ($columns as $column) {
                if (! array_key_exists($column, $payload)) {
                    $usable = false;

                    break;
                }

                $query->where($column, $payload[$column]);
            }

            if (! $usable) {
                continue;
            }

            $found = $query->value('id');

            if (is_int($found) || (is_string($found) && $found !== '')) {
                return $found;
            }
        }

        return null;
    }

    // Column names and timestamps arrive as mixed from PRAGMA rows and from a
    // payload built off the wire; anything not scalar is not a name.
    private static function asText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    // SQLite names its unique indexes in pragma_index_list; the primary key is
    // excluded because an id collision is not a second identity for one row.
    /**
     * @return list<list<string>>
     */
    private function uniqueIndexes(string $table): array
    {
        $connection = $this->db->connection();
        $indexes = [];

        try {
            $rows = $connection->select('SELECT name FROM pragma_index_list(?) WHERE "unique" = 1', [$table]);
        } catch (Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $name = is_object($row) && property_exists($row, 'name') ? self::asText($row->name) : '';

            if ($name === '') {
                continue;
            }

            $columns = [];

            foreach ($connection->select('SELECT name FROM pragma_index_info(?)', [$name]) as $info) {
                if (is_object($info) && property_exists($info, 'name')) {
                    $columns[] = self::asText($info->name);
                }
            }

            if ($columns !== [] && $columns !== ['id']) {
                $indexes[] = $columns;
            }
        }

        return $indexes;
    }
}
