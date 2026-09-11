<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\Query\Builder;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;
use stdClass;

// The creates a `primary_key_collision` hold still names, taken again as the
// device that sent them sent them. Two devices wrote two different rows under
// one pk, so the durable log holds both histories there and a strategy handed
// both answers with the row already sitting at the id.
/**
 * @link ../../../../.docs/features/sync/architecture.md#retrying-a-create-two-devices-minted-one-id-for
 */
final readonly class RetriedCollisionCreates
{
    public function __construct(
        private PersistedOpLogEntries $entries,
        private OpLogQuarantine $quarantine,
    ) {}

    // A hold is spent once the pass has judged the collision: the row placed,
    // or the verdict recorded afresh under a new id, which is why retiring the
    // old one cannot swallow the answer. A create turned away BEFORE the id is
    // looked at has been judged on nothing, and its hold stands.
    /**
     * @param  Builder  $held  Collision holds this pass may answer, already narrowed to its window.
     * @return array{rows: int, spent: list<int>} Rows taken again, and the holds that have had their answer.
     */
    public function replay(Builder $held, OpLogReplayer $replayer, int $userId, int $limit): array
    {
        $rows = 0;
        $spent = [];
        $standing = $this->quarantine->latestHoldId($userId);

        foreach ($this->byDevice($held, $limit) as $deviceId => $collision) {
            $entries = $this->entries->createsFromDevice($userId, self::rowsOf($collision), $deviceId);

            // A hold whose ops the log no longer carries has had no answer, and
            // retiring it would delete the only surviving record of the op.
            if ($entries === []) {
                continue;
            }

            $replayer->replay($entries, $userId, RowHistoryPolicy::AsGiven);
            $rows += count($collision);

            foreach ($collision as $row) {
                if ($this->quarantine->refusedPastTheCollision($userId, $deviceId, $row['table'], $row['pk'], $standing)) {
                    continue;
                }

                $spent = [...$spent, ...$row['ids']];
            }
        }

        return ['rows' => $rows, 'spent' => $spent];
    }

    // Grouped by the device whose create was refused, because that is what
    // separates the two rows sharing the id: the ops under it are one row per
    // author, and only the author the hold names is being retried. Every hold
    // id sits under the row it names, so one row's answer retires only its own.
    /**
     * @return array<string, list<array{table: string, pk: string, ids: list<int>}>>
     */
    private function byDevice(Builder $held, int $limit): array
    {
        $collisions = [];

        foreach ($held->limit($limit)->get(['id', 'table_name', 'pk', 'device_id']) as $row) {
            $hold = self::coordinates($row);

            if ($hold === null) {
                continue;
            }

            $key = $hold['table']."\0".$hold['pk'];
            $collisions[$hold['device']][$key] ??= ['table' => $hold['table'], 'pk' => $hold['pk'], 'ids' => []];
            $collisions[$hold['device']][$key]['ids'][] = $hold['id'];
        }

        return array_map(array_values(...), $collisions);
    }

    /**
     * @param  list<array{table: string, pk: string, ids: list<int>}>  $collision
     * @return list<array{table: string, pk: string}>
     */
    private static function rowsOf(array $collision): array
    {
        return array_map(
            static fn (array $row): array => ['table' => $row['table'], 'pk' => $row['pk']],
            $collision,
        );
    }

    // Null for a row missing any of the four a replay is addressed by, which
    // is a hold nothing could be retried from.
    /**
     * @return array{id: int, table: string, pk: string, device: string}|null
     */
    private static function coordinates(stdClass $row): ?array
    {
        $id = is_numeric($row->id ?? null) ? (int) $row->id : 0;
        $table = is_string($row->table_name ?? null) ? $row->table_name : '';
        $pk = isset($row->pk) && (is_string($row->pk) || is_numeric($row->pk)) ? (string) $row->pk : '';
        $device = is_string($row->device_id ?? null) ? $row->device_id : '';

        if ($id === 0 || $table === '' || $pk === '' || $device === '') {
            return null;
        }

        return ['id' => $id, 'table' => $table, 'pk' => $pk, 'device' => $device];
    }
}
