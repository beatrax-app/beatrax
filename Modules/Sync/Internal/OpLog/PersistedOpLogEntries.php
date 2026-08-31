<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

// The durable log read back as entries, HLC-ordered. One home because a full
// rebuild and a narrowed replay disagree only about WHICH rows they want, and
// a second copy of the row-to-entry mapping is a place for the two to drift.
final readonly class PersistedOpLogEntries
{
    // A frame carries at most MAX_OPS_PER_FRAME ops, so a single replay can
    // name a thousand rows. One `pk IN (...)` per chunk keeps the bind count
    // inside SQLite's ceiling and lets the (table_name, pk) index carry it.
    private const int PK_CHUNK = 400;

    public function __construct(private DatabaseManager $db) {}

    // Streamed rather than fetched: a whole-history rebuild held the raw rows
    // and the entries built from them at the same time, so the peak was twice
    // the log for the length of one query. Only the entries survive the loop.
    /**
     * @return list<OpLogEntry>
     */
    public function forUser(int $userId): array
    {
        $entries = [];

        foreach ($this->ordered($userId)->cursor() as $row) {
            $entry = self::fromRow($row);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    // Every entry belonging to the named rows, not merely the entries that
    // failed. A strategy resolves over the set it is handed: hand it one op of
    // a field and LWW makes that op the winner, hand it one field of a
    // CreateRow and the row is discarded as incomplete.
    /**
     * @param  list<array{table: string, pk: string}>  $rows
     * @return list<OpLogEntry> Ordered within each chunk; callers re-sort across the whole set.
     */
    public function forRows(int $userId, array $rows): array
    {
        $entries = [];

        foreach ($this->pksByTable($rows) as $table => $pks) {
            foreach (array_chunk($pks, self::PK_CHUNK) as $chunk) {
                $query = $this->ordered($userId)
                    ->where('table_name', $table)
                    ->whereIn('pk', $chunk);

                foreach (self::fromRows($query->get()->all()) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param  list<array{table: string, pk: string}>  $rows
     * @return array<string, list<string>>
     */
    private function pksByTable(array $rows): array
    {
        $byTable = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = $row['table']."\0".$row['pk'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $byTable[$row['table']][] = $row['pk'];
        }

        return $byTable;
    }

    // Null only under UnknownOpTypePolicy::Skip, and only for a row whose
    // op_type names an op this build has never heard of.
    public static function fromRow(
        object $row,
        UnknownOpTypePolicy $unknownOpType = UnknownOpTypePolicy::Fail,
    ): ?OpLogEntry {
        $vars = get_object_vars($row);
        $opTypeStr = is_string($vars['op_type'] ?? null) ? $vars['op_type'] : '';

        $opType = $unknownOpType->resolve($opTypeStr);
        if ($opType === null) {
            return null;
        }

        return new OpLogEntry(
            table: is_string($vars['table_name'] ?? null) ? $vars['table_name'] : '',
            pk: self::normalizePk($vars['pk'] ?? ''),
            field: is_string($vars['field'] ?? null) ? $vars['field'] : '',
            value: is_string($vars['value'] ?? null) ? $vars['value'] : null,
            hlcL: is_numeric($vars['hlc_l'] ?? null) ? (int) $vars['hlc_l'] : 0,
            hlcC: is_numeric($vars['hlc_c'] ?? null) ? (int) $vars['hlc_c'] : 0,
            deviceId: is_string($vars['device_id'] ?? null) ? $vars['device_id'] : '',
            opType: $opType,
            signature: is_string($vars['signature'] ?? null) ? $vars['signature'] : '',
            userId: is_numeric($vars['user_id'] ?? null) ? (int) $vars['user_id'] : 0,
            // A GDK-encrypted entry's value can only be decrypted with its
            // original epoch tag — dropping this on rebuild would silently
            // lose every sensitive-field edit.
            gdkEpoch: is_numeric($vars['gdk_epoch'] ?? null) ? (int) $vars['gdk_epoch'] : null,
            // What the origin device signed under. Dropping it here made the
            // rebuild recompute a v1 payload against the LOCAL user id, so
            // every peer entry re-verified as forged.
            originUserId: is_numeric($vars['origin_user_id'] ?? null) ? (int) $vars['origin_user_id'] : null,
        );
    }

    // A numeric pk normalises to int; a non-numeric string pk (composite or
    // UUID key) is preserved verbatim; anything else collapses to ''.
    public static function normalizePk(mixed $pkRaw): int|string
    {
        if (is_numeric($pkRaw)) {
            return (int) $pkRaw;
        }

        return is_string($pkRaw) ? $pkRaw : '';
    }

    // Fails rather than skips on an op_type this build has never heard of: a
    // row read back from THIS device's own log names an op this device wrote,
    // so an unknown one is a downgrade, not a peer speaking a later dialect.
    /**
     * @param  array<int, mixed>  $rows
     * @return list<OpLogEntry>
     */
    public static function fromRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $entry = is_object($row) ? self::fromRow($row) : null;

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function ordered(int $userId): Builder
    {
        return $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->orderBy('hlc_l')
            ->orderBy('hlc_c')
            ->orderBy('device_id');
    }
}
