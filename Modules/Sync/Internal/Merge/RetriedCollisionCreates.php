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
    public function __construct(private PersistedOpLogEntries $entries) {}

    // The hold ids are read BEFORE the replay, so a create refused again is
    // recorded afresh under a new id and retiring these cannot swallow it.
    /**
     * @param  Builder  $held  Collision holds this pass may answer, already narrowed to its window.
     * @return array{rows: int, spent: list<int>} Rows taken again, and the holds that have had their answer.
     */
    public function replay(Builder $held, OpLogReplayer $replayer, int $userId, int $limit): array
    {
        $rows = 0;
        $spent = [];

        foreach ($this->byDevice($held, $limit) as $deviceId => $collision) {
            $entries = $this->entries->createsFromDevice($userId, $collision['rows'], $deviceId);

            // A hold whose ops the log no longer carries has had no answer, and
            // retiring it would delete the only surviving record of the op.
            if ($entries === []) {
                continue;
            }

            $replayer->replay($entries, $userId, RowHistoryPolicy::AsGiven);
            $rows += count($collision['rows']);
            $spent = [...$spent, ...$collision['ids']];
        }

        return ['rows' => $rows, 'spent' => $spent];
    }

    // Grouped by the device whose create was refused, because that is what
    // separates the two rows sharing the id: the ops under it are one row per
    // author, and only the author the hold names is being retried.
    /**
     * @return array<string, array{ids: list<int>, rows: list<array{table: string, pk: string}>}>
     */
    private function byDevice(Builder $held, int $limit): array
    {
        $collisions = [];
        $seen = [];

        foreach ($held->limit($limit)->get(['id', 'table_name', 'pk', 'device_id']) as $row) {
            $hold = self::coordinates($row);

            if ($hold === null) {
                continue;
            }

            $key = $hold['device']."\0".$hold['table']."\0".$hold['pk'];
            $collisions[$hold['device']] ??= ['ids' => [], 'rows' => []];
            $collisions[$hold['device']]['ids'][] = $hold['id'];

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $collisions[$hold['device']]['rows'][] = ['table' => $hold['table'], 'pk' => $hold['pk']];
            }
        }

        return $collisions;
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
