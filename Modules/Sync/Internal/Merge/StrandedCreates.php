<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Public\Exceptions\ColumnNotDeclaredException;
use Modules\Core\Public\Support\SchemaShape;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineOutcome;

// Creates the durable log carries whose row is in no table. Two shapes, and a
// check that asks only about primary keys sees one of them: the pk is free and
// nothing was written, or the pk is TAKEN by a different row and the arriving
// one went nowhere at all.
/**
 * @link ../../../../.docs/features/sync/architecture.md#rows-the-log-holds-and-the-table-does-not
 */
final readonly class StrandedCreates
{
    // Every column this census names, and the reason it is written down. SQLite
    // reads a double-quoted name matching no column as a string LITERAL, so a
    // predicate on a column whose migration has not run is not an error: it is
    // silently true, and `self_retired_at` absent passed every peer as self.
    private const array REQUIRED_COLUMNS = [
        'op_log_entries' => ['id', 'user_id', 'table_name', 'pk', 'device_id', 'op_type', 'field', 'value', 'hlc_l', 'hlc_c'],
        'op_log_quarantine' => ['user_id', 'table_name', 'pk', 'device_id', 'op_type', 'reason'],
        'device_registry' => ['user_id', 'device_id', 'is_self', 'self_retired_at'],
    ];

    public function __construct(
        private DatabaseManager $db,
        private PeerRowAliases $aliases,
        private RowOwnership $ownership,
    ) {}

    // Every row the log claims, and the ones nothing holds split by what put
    // them there, per table. The counts are exact rather than sampled: a floor
    // would read the same whether one row or forty were missing.
    /**
     * @return array{checked: int, unplaced: array<string, int>, removedHere: array<string, int>, held: array<string, int>}
     *
     * @throws ColumnNotDeclaredException
     */
    public function census(int $userId): array
    {
        $this->assertEveryColumnIsThere();

        $checked = 0;
        $causes = ['unplaced' => [], 'removedHere' => [], 'held' => []];

        foreach ($this->tables($userId) as $table) {
            $checked += $this->claimedRows($table, $userId);

            foreach ($this->strandedIn($table, $userId) as $cause => $count) {
                if ($count > 0) {
                    $causes[$cause][$table] = $count;
                }
            }
        }

        return [
            'checked' => $checked,
            'unplaced' => $causes['unplaced'],
            'removedHere' => $causes['removedHere'],
            'held' => $causes['held'],
        ];
    }

    // Refused rather than guessed. The window between a build landing and its
    // migration running is an ordinary state, and in it every answer below is
    // wrong in a direction nothing reports -- so the census declines to give one.
    /**
     * @throws ColumnNotDeclaredException
     */
    private function assertEveryColumnIsThere(): void
    {
        $connection = $this->db->connection();

        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            $missing = SchemaShape::missingColumns($connection, $table, $columns);

            if ($missing !== []) {
                throw ColumnNotDeclaredException::on($table, $missing);
            }
        }
    }

    /**
     * @return array{unplaced: int, removedHere: int, held: int}
     */
    private function strandedIn(string $table, int $userId): array
    {
        $absent = array_fill_keys($this->absentPks($table, $userId), true);
        $counts = ['unplaced' => 0, 'removedHere' => 0, 'held' => 0];

        // Both candidate sets in one walk, and an id in both is walked once:
        // it is the same question about the same id either way.
        foreach ($this->contestedPks($table, $userId) + $absent as $pk => $ignored) {
            foreach ($this->unlandedRows($table, (string) $pk, $userId, ! isset($absent[$pk])) as $cause) {
                $counts[$cause]++;
            }
        }

        return $counts;
    }

    // Counted per ROW rather than per author: two devices that wrote the same
    // logical row under one id and lost it lost one row, not two.
    /**
     * @return list<'unplaced'|'removedHere'|'held'>
     */
    private function unlandedRows(string $table, string $pk, int $userId, bool $pkIsHere): array
    {
        $causes = [];

        foreach ($this->rowsUnder($table, $pk, $userId) as $authors) {
            if (! $this->landed($table, $pk, $authors, $userId, $pkIsHere)) {
                $causes[] = $this->causeOf($table, $pk, $authors, $userId);
            }
        }

        return $causes;
    }

    // What the absence means, asked of the log rather than of a list of table
    // names -- a rule naming a table is blind to the next one that legitimately
    // loses a row. Only `unplaced` is a row a repair could still place, and it
    // is the only one that reaches a reader as something to act on.
    /**
     * @param  list<string>  $authors
     * @return 'unplaced'|'removedHere'|'held'
     */
    private function causeOf(string $table, string $pk, array $authors, int $userId): string
    {
        if ($this->writtenHere($authors, $userId)) {
            return 'removedHere';
        }

        return $this->heldTerminally($table, $pk, $authors, $userId) ? 'held' : 'unplaced';
    }

    // A create THIS device authored is proof the row was here: the capture runs
    // on the write, so the row existed to be written. Gone now, and with no
    // tombstone, it was removed locally -- by a migration, a cascade, a repair
    // -- and no peer holds a copy of it for the applier to have failed to place.
    /**
     * @param  list<string>  $authors
     */
    private function writtenHere(array $authors, int $userId): bool
    {
        return $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->whereIn('device_id', $authors)
            ->where(static fn (Builder $row): Builder => $row
                ->where('is_self', 1)
                ->orWhereNotNull('self_retired_at'))
            ->exists();
    }

    // A peer's create the quarantine already answered, under a verdict
    // `QuarantineOutcome` lists as terminal -- so nothing arriving later takes
    // it again and the row is not a repair waiting to be run. A hold on a
    // recoverable reason is not one: that row is still owed.
    /**
     * @param  list<string>  $authors
     */
    private function heldTerminally(string $table, string $pk, array $authors, int $userId): bool
    {
        return $this->db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('pk', $pk)
            ->whereIn('device_id', $authors)
            ->where('op_type', OpType::CreateRow->value)
            ->whereIn('reason', QuarantineOutcome::terminalReasonValues())
            ->exists();
    }

    // Which of the devices claiming one id claimed the SAME row. Two creates
    // the natural key cannot tell apart are one row, so an edit either device
    // made afterwards speaks for both; two it can are the collision, and
    // reading one's edits into the other's payload would hide the loss.
    /**
     * @return list<list<string>>
     */
    private function rowsUnder(string $table, string $pk, int $userId): array
    {
        $devices = $this->authorsOf($table, $pk, $userId);

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

        $rows = $this->db->connection()->table('op_log_entries')
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
        $names = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('op_type', OpType::CreateRow->value)
            ->groupBy('table_name')
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

    private function claimedRows(string $table, int $userId): int
    {
        return $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->distinct()
            ->count('pk');
    }

    // Only two kinds of id can be hiding a loss, and both are asked for in
    // SQL so that only the answer crosses into memory. Reading every create
    // group in to sift them here is the whole log on a phone, and the log is
    // the one table that grows with every mutation for the life of the install.
    /**
     * @return array<int|string, true>
     */
    private function contestedPks(string $table, int $userId): array
    {
        $pks = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->groupBy('pk')
            ->havingRaw('COUNT(DISTINCT device_id) > 1')
            ->pluck('pk');

        return array_fill_keys($this->textColumn($pks), true);
    }

    // An id no row of THIS reader's is at. One autoincrement serves every
    // reader on the install, so the scope is the same one the applier writes
    // through; unscoped, a housemate's row would answer for this one.
    /**
     * @return list<string>
     */
    private function absentPks(string $table, int $userId): array
    {
        $pks = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->whereNotExists(fn (Builder $row) => $this->ownership->scopeToUser(
                $row->from($table)->whereColumn($table.'.id', 'op_log_entries.pk'),
                $table,
                $userId,
            ))
            ->groupBy('pk')
            ->pluck('pk');

        return $this->textColumn($pks);
    }

    /**
     * @return list<string>
     */
    private function authorsOf(string $table, string $pk, int $userId): array
    {
        $devices = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->where('pk', $pk)
            ->groupBy('device_id')
            ->pluck('device_id');

        return $this->textColumn($devices);
    }

    private function tombstoned(string $table, string $pk, int $userId): bool
    {
        return $this->db->connection()->table('op_log_entries')
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
