<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Sync\Internal\OpLog\OpType;

// Creates the durable log carries whose row is in no table. Two shapes, and a
// check that asks only about primary keys sees one of them: the pk is free and
// nothing was written, or the pk is TAKEN by a different row and the arriving
// one went nowhere at all.
/**
 * @link ../../../../.docs/features/sync/architecture.md#rows-the-log-holds-and-the-table-does-not
 */
final readonly class StrandedCreates
{
    private const string LOG = 'op_log_entries';

    public function __construct(
        private DatabaseManager $db,
        private PeerRowAliases $aliases,
        private RowOwnership $ownership,
    ) {}

    // Every row the log claims, and how many of them nothing holds, per table.
    // The count is exact rather than sampled: a floor would read the same
    // whether one row or forty were missing.
    /**
     * @return array{checked: int, stranded: array<string, int>}
     */
    public function census(int $userId): array
    {
        $checked = 0;
        $stranded = [];

        foreach ($this->tables($userId) as $table) {
            $groups = $this->createGroups($table, $userId);
            $checked += count($groups);
            $count = $this->strandedIn($table, $groups, $userId);

            if ($count > 0) {
                $stranded[$table] = $count;
            }
        }

        return ['checked' => $checked, 'stranded' => $stranded];
    }

    // Only two kinds of pk can be hiding a loss, and asking about the rest
    // would report every row whose natural key was edited after it was
    // created: one no row of this reader's is at, and one two devices both
    // minted. A pk one device minted and a row is sitting at is that row.
    /**
     * @param  array<int|string, list<string>>  $groups
     */
    private function strandedIn(string $table, array $groups, int $userId): int
    {
        $present = $this->presentPks($table, array_keys($groups), $userId);
        $stranded = 0;

        foreach ($groups as $pk => $devices) {
            $here = isset($present[$pk]);

            if (! $here || count($devices) > 1) {
                $stranded += $this->unlandedRows($table, (string) $pk, $devices, $userId, $here);
            }
        }

        return $stranded;
    }

    // Counted per ROW rather than per author: two devices that wrote the same
    // logical row under one id and lost it lost one row, not two.
    /**
     * @param  list<string>  $devices
     */
    private function unlandedRows(string $table, string $pk, array $devices, int $userId, bool $pkIsHere): int
    {
        $unlanded = 0;

        foreach ($this->rowsUnder($table, $pk, $devices, $userId) as $authors) {
            if (! $this->landed($table, $pk, $authors, $userId, $pkIsHere)) {
                $unlanded++;
            }
        }

        return $unlanded;
    }

    // Which of the devices claiming one id claimed the SAME row. Two creates
    // the natural key cannot tell apart are one row, so an edit either device
    // made afterwards speaks for both; two it can are the collision, and
    // reading one's edits into the other's payload would hide the loss.
    /**
     * @param  list<string>  $devices
     * @return list<list<string>>
     */
    private function rowsUnder(string $table, string $pk, array $devices, int $userId): array
    {
        if (count($devices) < 2) {
            return [$devices];
        }

        $rows = [];

        foreach ($devices as $deviceId) {
            // The create alone, never the Sets: the grouping decides whose
            // edits may be read, so reading them first would answer with what
            // it is being asked.
            $key = $this->aliases->naturalKeyOf($table, $this->payload($table, $pk, [$deviceId], $userId, false)) ?? $deviceId;
            $rows[$key][] = $deviceId;
        }

        return array_values($rows);
    }

    // The three legitimate absences, in the order they are cheapest to ask.
    // An alias is the two devices having agreed which row the id means, which
    // is what a re-home records; a tombstone is a row deliberately gone; and a
    // natural key answers for the rest, including every derived id.
    /**
     * @param  list<string>  $authors
     */
    private function landed(string $table, string $pk, array $authors, int $userId, bool $pkIsHere): bool
    {
        if ($this->aliased($table, $pk, $authors, $userId) || $this->tombstoned($table, $pk, $userId)) {
            return true;
        }

        $payload = $this->payload($table, $pk, $authors, $userId, true);

        // Where no natural key can identify the payload there is nothing to
        // ask beyond the id, so the pk answers -- the older, weaker question,
        // kept for exactly the tables it is the only one available for.
        return $this->aliases->naturalKeyIdentifies($table, $payload)
            ? $this->aliases->localTwinOf($table, $payload) !== null
            : $pkIsHere;
    }

    /**
     * @param  list<string>  $authors
     */
    private function aliased(string $table, string $pk, array $authors, int $userId): bool
    {
        foreach ($authors as $deviceId) {
            if ($this->aliases->localFor($table, $deviceId, $pk, $userId) !== null) {
                return true;
            }
        }

        return false;
    }

    // Rebuilt the way the applier builds it: every field the log carries for
    // this row from the devices that wrote it, later Sets over the create's
    // own values so a column edited after the fact is not read as a row that
    // never landed, the owner re-seeded, and the ids it names translated.
    /**
     * @param  list<string>  $authors
     * @return array<string, mixed>
     */
    private function payload(string $table, string $pk, array $authors, int $userId, bool $withEdits): array
    {
        $opTypes = $withEdits ? [OpType::CreateRow->value, OpType::Set->value] : [OpType::CreateRow->value];

        $rows = $this->db->connection()->table(self::LOG)
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('pk', $pk)
            ->whereIn('device_id', $authors)
            ->whereIn('op_type', $opTypes)
            ->orderBy('hlc_l')->orderBy('hlc_c')->orderBy('id')
            ->get(['field', 'value']);

        $payload = [];

        foreach ($rows as $row) {
            $field = is_string($row->field ?? null) ? $row->field : '';

            if ($field !== '') {
                $payload[$field] = is_string($row->value ?? null) ? json_decode($row->value, true) : null;
            }
        }

        $payload['id'] = $pk;

        if ($this->ownership->hasUserIdColumn($table)) {
            $payload['user_id'] = $userId;
        }

        return $this->aliases->translate($table, $authors[0] ?? '', $payload, $userId);
    }

    // `users` is left out because the applier never addresses it by the wire
    // pk: the row is found by the session's own id, so an id no row here wears
    // is the ordinary case rather than a row that went missing.
    /**
     * @return list<string>
     */
    private function tables(int $userId): array
    {
        $names = $this->db->connection()->table(self::LOG)
            ->where('user_id', $userId)
            ->where('op_type', OpType::CreateRow->value)
            ->distinct()
            ->orderBy('table_name')
            ->pluck('table_name');

        $tables = [];

        foreach ($this->textColumn($names) as $table) {
            if (! $this->ownership->isSelfScoped($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    // One read per table for what would otherwise be three: which ids the log
    // claims, which of them two devices both claim, and who claimed each.
    /**
     * @return array<int|string, list<string>>
     */
    private function createGroups(string $table, int $userId): array
    {
        $rows = $this->db->connection()->table(self::LOG)
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->distinct()
            ->get(['pk', 'device_id']);

        $groups = [];

        foreach ($rows as $row) {
            $pk = is_string($row->pk ?? null) || is_numeric($row->pk ?? null) ? (string) $row->pk : '';
            $device = is_string($row->device_id ?? null) ? $row->device_id : '';

            if ($pk !== '' && $device !== '') {
                $groups[$pk][] = $device;
            }
        }

        return $groups;
    }

    /**
     * @param  list<int|string>  $pks
     * @return array<int|string, true>
     */
    private function presentPks(string $table, array $pks, int $userId): array
    {
        $query = $this->db->connection()->table($table)->whereIn('id', $pks);

        return array_fill_keys(
            $this->textColumn($this->ownership->scopeToUser($query, $table, $userId)->pluck('id')),
            true,
        );
    }

    private function tombstoned(string $table, string $pk, int $userId): bool
    {
        return $this->db->connection()->table(self::LOG)
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('pk', $pk)
            ->where('op_type', OpType::DeleteTombstone->value)
            ->exists();
    }

    // A pk is a varchar in the log and an integer in the table it names, so
    // both sides are read as text before they are compared.
    /**
     * @param  Collection<int, mixed>  $values
     * @return list<string>
     */
    private function textColumn(Collection $values): array
    {
        $text = [];

        foreach ($values as $value) {
            if (is_string($value) || is_numeric($value)) {
                $text[] = (string) $value;
            }
        }

        return $text;
    }
}
