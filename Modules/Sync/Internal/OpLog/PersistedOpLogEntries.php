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
    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<OpLogEntry>
     */
    public function forUser(int $userId): array
    {
        return $this->hydrate($this->ordered($userId)->get()->all());
    }

    // Every entry belonging to the named rows, not merely the entries that
    // failed. A strategy resolves over the set it is handed: hand it one op of
    // a field and LWW makes that op the winner, hand it one field of a
    // CreateRow and the row is discarded as incomplete.
    /**
     * @param  list<array{table: string, pk: string}>  $rows
     * @return list<OpLogEntry>
     */
    public function forRows(int $userId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $query = $this->ordered($userId)->where(function (Builder $outer) use ($rows): void {
            foreach ($rows as $row) {
                $outer->orWhere(function (Builder $inner) use ($row): void {
                    $inner->where('table_name', $row['table'])->where('pk', $row['pk']);
                });
            }
        });

        return $this->hydrate($query->get()->all());
    }

    public static function fromRow(object $row): OpLogEntry
    {
        $vars = get_object_vars($row);
        $opTypeStr = is_string($vars['op_type'] ?? null) ? $vars['op_type'] : '';

        return new OpLogEntry(
            table: is_string($vars['table_name'] ?? null) ? $vars['table_name'] : '',
            pk: self::normalizePk($vars['pk'] ?? ''),
            field: is_string($vars['field'] ?? null) ? $vars['field'] : '',
            value: is_string($vars['value'] ?? null) ? $vars['value'] : null,
            hlcL: is_numeric($vars['hlc_l'] ?? null) ? (int) $vars['hlc_l'] : 0,
            hlcC: is_numeric($vars['hlc_c'] ?? null) ? (int) $vars['hlc_c'] : 0,
            deviceId: is_string($vars['device_id'] ?? null) ? $vars['device_id'] : '',
            opType: OpType::from($opTypeStr),
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

    private function ordered(int $userId): Builder
    {
        return $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->orderBy('hlc_l')
            ->orderBy('hlc_c')
            ->orderBy('device_id');
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<OpLogEntry>
     */
    private function hydrate(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $entries[] = self::fromRow($row);
            }
        }

        return $entries;
    }
}
