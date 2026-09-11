<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Psr\Log\LoggerInterface;
use Throwable;

// Which local row a peer's id means, when two devices minted different ids for
// the same logical row. Each seeds its own reference data, so the peer's tax
// category arrives as id 109 and collides with local row 13 on (user_id, name).

// The collision is harmless — the row IS here. The peer's identity for it is
// what was lost, so nineteen tax tags naming 109 failed their foreign key.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class PeerRowAliases
{
    private const string TABLE = 'op_log_row_aliases';

    public function __construct(
        private DatabaseManager $db,
        private CoveredTableOrder $tableOrder,
        private LoggerInterface $log,
        private SensitiveFieldRegistry $sensitive = new SensitiveFieldRegistry,
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

        $this->rememberAs($table, $deviceId, $remoteId, $localId, $payload, $userId);
    }

    // The same pair for a twin remember() cannot find because it does not
    // exist yet: a create re-homed under an id this device minted is the row
    // the peer's id names, and nothing else on the table holds its natural key.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function rememberAs(string $table, string $deviceId, int|string $remoteId, int|string $localId, array $payload, int $userId): void
    {
        $this->db->connection()->table(self::TABLE)->insertOrIgnore([
            'user_id' => $userId,
            'table_name' => $table,
            'device_id' => $deviceId,
            'remote_id' => (string) $remoteId,
            'local_id' => (string) $localId,
            'created_at' => self::asText($payload['updated_at'] ?? $payload['created_at'] ?? ''),
        ]);
    }

    // Whether a row stored with this payload could be found by localTwinOf()
    // afterwards. Asked before a create is re-homed under a fresh id: a replay
    // that cannot recognise the re-homed row inserts a second copy of it, and
    // every replay after that adds another.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function naturalKeyIdentifies(string $table, array $payload): bool
    {
        return $this->usableIndexes($table, $payload) !== [];
    }

    // The id to address a row by HERE: the peer's own where the two agree, the
    // local twin's where they do not.
    public function resolvePk(string $table, string $deviceId, int|string $pk, int $userId): int|string
    {
        $local = $this->localFor($table, $deviceId, $pk, $userId);

        if ($local === null) {
            return $pk;
        }

        return is_numeric($local) ? (int) $local : $local;
    }

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

    // Entries for one pk whose devices resolve it to DIFFERENT local rows are
    // not one row's history. partitionByOpType keys a field group by pk alone,
    // so two devices that minted one id put both rows' Sets in one group, to
    // be resolved by a single LWW and written wherever the earliest one points.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return list<array{pk: int|string, fields: array<string, list<OpLogEntry>>}>
     */
    public function splitFieldsByLocalRow(string $table, int|string $pk, array $fields, int $userId): array
    {
        $rows = [];

        foreach ($fields as $field => $entries) {
            foreach ($entries as $entry) {
                $local = $this->resolvePk($table, $entry->deviceId, $pk, $userId);
                $key = (string) $local;

                $rows[$key] ??= ['pk' => $local, 'fields' => []];
                $rows[$key]['fields'][$field][] = $entry;
            }
        }

        return array_values($rows);
    }

    // Called through rather than guarded: CoveredTableOrder answers with an
    // empty list rather than raising, and reports the schema fault itself. A
    // catch here would be a second one behind a first that already swallows
    // everything, which is a catch that can never fire.
    /**
     * @return array<string, string>
     */
    private function parentColumns(string $table): array
    {
        return $this->tableOrder->parentColumns($table);
    }

    // The local row holding the same natural key: every unique index the table
    // declares apart from the primary key, tried against the payload. Derived
    // from the schema so the answer follows the index, not a copy of it.
    /**
     * @param  array<string, mixed>  $payload
     */
    private function localTwinOf(string $table, array $payload): int|string|null
    {
        foreach ($this->usableIndexes($table, $payload) as $columns) {
            $query = $this->db->connection()->table($table);

            foreach ($columns as $column) {
                $query->where($column, $payload[$column]);
            }

            $found = $query->value('id');

            if (is_int($found) || (is_string($found) && $found !== '')) {
                return $found;
            }
        }

        return null;
    }

    // The indexes that can answer for THIS payload, which is what makes the
    // question the re-home gate asks the same question the finder answers.
    /**
     * @param  array<string, mixed>  $payload
     * @return list<list<string>>
     */
    private function usableIndexes(string $table, array $payload): array
    {
        $usable = [];

        foreach ($this->uniqueIndexes($table) as $columns) {
            if ($this->identifies($table, $columns, $payload)) {
                $usable[] = $columns;
            }
        }

        return $usable;
    }

    // A null is not a key: SQLite counts nulls as distinct, so the index
    // constrains nothing, and `where($col, null)` is `whereNull`, which hands
    // back an arbitrary row holding one as though it were the twin. Nor is
    // AEAD ciphertext, re-sealed under a fresh nonce on every write.
    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $payload
     */
    private function identifies(string $table, array $columns, array $payload): bool
    {
        foreach ($columns as $column) {
            if (! array_key_exists($column, $payload) || $payload[$column] === null) {
                return false;
            }

            if ($this->sensitive->isSensitive($table, $column)) {
                return false;
            }
        }

        return true;
    }

    // Column names and timestamps arrive as mixed from PRAGMA rows and from a
    // payload built off the wire; anything not scalar is not a name.
    private static function asText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    // SQLite names its unique indexes in pragma_index_list; the primary key is
    // excluded because an id collision is not a second identity for one row.

    // Partial indexes are excluded too: their WHERE predicate is not read here,
    // so a row the index never covered would answer as a twin. `pots` declares
    // only one unique index and it is partial, which is how a peer's pot came
    // to match an arbitrary unlinked pot of this device's.
    /**
     * @return list<list<string>>
     */
    private function uniqueIndexes(string $table): array
    {
        $connection = $this->db->connection();
        $indexes = [];

        try {
            $rows = $connection->select('SELECT name FROM pragma_index_list(?) WHERE "unique" = 1 AND partial = 0', [$table]);
        } catch (Throwable $e) {
            // No indexes means no natural key, which means no alias: the peer's
            // row is inserted beside the local one holding the same key instead
            // of being recognised as it.
            $this->log->warning('PeerRowAliases: could not read the unique indexes, so a row already here under another id will not be matched.', [
                'table' => $table,
                'exception' => $e::class,
            ]);

            return [];
        }

        foreach ($rows as $row) {
            $name = is_object($row) && property_exists($row, 'name') ? self::asText($row->name) : '';

            if ($name === '') {
                continue;
            }

            $columns = $this->indexColumns($name);

            if ($columns !== [] && $columns !== ['id']) {
                $indexes[] = $columns;
            }
        }

        return $indexes;
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $name): array
    {
        $columns = [];

        foreach ($this->db->connection()->select('SELECT name FROM pragma_index_info(?)', [$name]) as $info) {
            if (is_object($info) && property_exists($info, 'name')) {
                $columns[] = self::asText($info->name);
            }
        }

        return $columns;
    }
}
