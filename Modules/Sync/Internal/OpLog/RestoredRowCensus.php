<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

// Whether each id a rebuild deleted is answered for by the time it commits.
// Three answers count and they are different questions: the row is here again,
// the log tombstones it, or this device stored it under an id of its own. Only
// the third needs reading past the id, which is why it was the one that broke.
final readonly class RestoredRowCensus
{
    // A device id is free text and a pk is a string, so the pair needs a
    // separator neither can hold.
    private const string PK_SEPARATOR = "\0";

    public function __construct(private DatabaseManager $db) {}

    /**
     * @param  list<int>  $pks
     * @return list<int>
     */
    public function accountedFor(string $table, array $pks, int $userId): array
    {
        $present = self::asIds($this->db->connection()->table($table)->whereIn('id', $pks)->pluck('id'));
        $aliased = $this->aliasedToALiveRow($table, $pks, $userId);

        $tombstoned = self::asIds($this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::DeleteTombstone->value)
            ->whereIn('pk', self::asText($pks))
            ->distinct()
            ->pluck('pk'));

        return [...$present, ...$aliased, ...$tombstoned];
    }

    // An alias row says "device D's id R is our id L", and both halves are
    // load-bearing. Read on R alone, a peer's alias for ITS row 4218 answered
    // for this device's deleted row 4218, and an alias whose L named a row that
    // never landed still counted as one that came back.
    /**
     * @param  list<int>  $pks
     * @return list<int>
     */
    private function aliasedToALiveRow(string $table, array $pks, int $userId): array
    {
        $remoteIds = self::asText($pks);

        $rows = $this->db->connection()->table('op_log_row_aliases')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->whereIn('remote_id', $remoteIds)
            ->get(['device_id', 'remote_id', 'local_id']);

        $authors = $this->createAuthorsByPk($table, $remoteIds, $userId);
        $localIds = self::asIds($rows->pluck('local_id'));
        $live = array_flip(self::asIds($this->db->connection()->table($table)->whereIn('id', $localIds)->pluck('id')));

        $accounted = [];

        foreach ($rows as $row) {
            $remote = is_numeric($row->remote_id ?? null) ? (int) $row->remote_id : null;
            $local = is_numeric($row->local_id ?? null) ? (int) $row->local_id : null;
            $device = is_string($row->device_id ?? null) ? $row->device_id : '';

            if ($remote === null || $local === null || ! isset($live[$local])) {
                continue;
            }

            if (isset($authors[$device.self::PK_SEPARATOR.$remote])) {
                $accounted[] = $remote;
            }
        }

        return $accounted;
    }

    // Which device announced the create for each pk. The alias is only that
    // device's word for the row, so an alias scoped to anyone else accounts for
    // nothing the replay was asked to put back.
    /**
     * @param  list<string>  $remoteIds
     * @return array<string, true>
     */
    private function createAuthorsByPk(string $table, array $remoteIds, int $userId): array
    {
        $authors = [];

        foreach ($this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->whereIn('pk', $remoteIds)
            ->distinct()
            ->get(['device_id', 'pk']) as $row) {
            $device = is_string($row->device_id ?? null) ? $row->device_id : '';
            $pk = is_scalar($row->pk ?? null) ? (string) $row->pk : '';

            $authors[$device.self::PK_SEPARATOR.$pk] = true;
        }

        return $authors;
    }

    /**
     * @param  list<int>  $pks
     * @return list<string>
     */
    private static function asText(array $pks): array
    {
        return array_map(static fn (int $pk): string => (string) $pk, $pks);
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<int>
     */
    private static function asIds(Collection $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
