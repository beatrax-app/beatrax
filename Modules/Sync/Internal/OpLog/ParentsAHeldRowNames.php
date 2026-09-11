<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

// A row held for a missing reference NAMES the row that is missing, whose own
// ops sit in the log unreplayed. Replaying only the held row re-ran the same
// failure; pulling its parents in is what lets the parent land, or records the
// id pair when it is already here under a locally minted id.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#telling-not-yet-openable-apart-from-never-openable-here
 */
final readonly class ParentsAHeldRowNames
{
    // Deep enough for a child, its parent and its grandparent, which is as
    // far as the covered schema nests.
    private const int WALK_LIMIT = 3;

    private CoveredTableOrder $order;

    public function __construct(private DatabaseManager $db, LoggerInterface $log)
    {
        // Built here rather than injected, the way the replayer builds its own:
        // the registry is a value, and a second construction site is how the
        // two ideas of which tables are covered drift apart.
        $this->order = new CoveredTableOrder($db, new MergeRulesRegistry, $log);
    }

    /**
     * @param  list<array{table: string, pk: string}>  $rows
     * @return list<array{table: string, pk: string}>
     */
    public function including(array $rows, int $userId): array
    {
        $seen = [];

        foreach ($rows as $row) {
            $seen[$row['table'].':'.$row['pk']] = true;
        }

        // A grandparent can be missing too, so the walk follows what it finds.
        // Bounded because a cycle in the foreign keys would otherwise not end.
        $frontier = $rows;

        for ($depth = 0; $depth < self::WALK_LIMIT && $frontier !== []; $depth++) {
            $next = [];

            foreach ($frontier as $row) {
                foreach ($this->namedBy($row, $userId) as $parent) {
                    if (isset($seen[$parent['table'].':'.$parent['pk']])) {
                        continue;
                    }

                    $seen[$parent['table'].':'.$parent['pk']] = true;
                    $rows[] = $parent;
                    $next[] = $parent;
                }
            }

            $frontier = $next;
        }

        return $rows;
    }

    // The parent rows one held row points at, read off its own create ops
    // rather than off the table — the row is held precisely because it is not
    // in the table.
    /**
     * @param  array{table: string, pk: string}  $row
     * @return list<array{table: string, pk: string}>
     */
    private function namedBy(array $row, int $userId): array
    {
        try {
            $parents = $this->order->parentColumns($row['table']);
        } catch (Throwable) {
            return [];
        }

        unset($parents['user_id']);

        if ($parents === []) {
            return [];
        }

        $named = [];

        $entries = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $row['table'])
            ->where('pk', $row['pk'])
            ->whereIn('field', array_keys($parents))
            ->get(['field', 'value']);

        foreach ($entries as $entry) {
            $field = is_string($entry->field ?? null) ? $entry->field : '';
            $parent = $parents[$field] ?? null;
            $value = is_string($entry->value ?? null) ? json_decode($entry->value, true) : null;

            if ($parent === null || (! is_int($value) && ! is_string($value)) || (string) $value === '') {
                continue;
            }

            $named[] = ['table' => $parent, 'pk' => (string) $value];
        }

        return $named;
    }
}
