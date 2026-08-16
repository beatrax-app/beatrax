<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Sync\Internal\Config\CoveredTableOrder;

// Writes the rows that already existed when sync was switched on into the op
// log as CREATE_ROW ops. Capture is event-driven, so a device that was used
// before pairing had an empty log and handed its first peer nothing — the
// phone sat on "0 of 0 records" while the desktop held years of data.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogBackfiller
{
    // Rows per SELECT. The log is written entry-by-entry regardless; this
    // only bounds how much of a table is held in memory at once.
    private const CHUNK = 200;

    // Never emitted as a field: the row's identity travels as the op's pk,
    // and re-stating it invites a create whose pk and user_id disagree.
    private const SKIPPED_COLUMNS = ['id'];

    // Covered tables that carry no user_id of their own. Each is scoped
    // through the parent column named here, whose table does carry one.
    private const PARENT_SCOPE = [
        'rule_conditions' => ['rule_id', 'categorization_rules'],
        'rule_actions' => ['rule_id', 'categorization_rules'],
    ];

    public function __construct(
        private DatabaseManager $db,
        private CoveredTableOrder $order,
    ) {}

    // Returns the number of rows captured. Idempotent by construction: a
    // user with any op-log history has already been captured (or has been
    // syncing all along), and re-running would duplicate every row.
    public function backfill(int $userId, OpLogWriter $writer): int
    {
        $connection = $this->db->connection();

        if ($this->hasHistory($connection, $userId)) {
            return 0;
        }

        $captured = 0;

        // Parents first, so the entries a peer replays arrive in an order its
        // foreign keys accept.
        foreach ($this->order->insertionOrder() as $table) {
            $captured += $this->captureTable($connection, $table, $userId, $writer);
        }

        return $captured;
    }

    private function hasHistory(Connection $connection, int $userId): bool
    {
        return $connection->table('op_log_entries')
            ->where('user_id', $userId)
            ->exists();
    }

    private function captureTable(
        Connection $connection,
        string $table,
        int $userId,
        OpLogWriter $writer,
    ): int {
        $columns = $this->columnsOf($connection, $table);

        if ($columns === []) {
            return 0;
        }

        $captured = 0;

        $this->scopedQuery($connection, $table, $userId, $columns)
            ->orderBy($table.'.id')
            ->chunk(self::CHUNK, function ($rows) use ($table, $writer, &$captured): void {
                foreach ($rows as $row) {
                    /** @var array<string, mixed> $fields */
                    $fields = (array) $row;
                    $pk = $fields['id'] ?? null;
                    unset($fields['id']);

                    if (! is_int($pk) && ! is_string($pk)) {
                        continue;
                    }

                    $writer->writeCreateRow($table, $pk, $fields);
                    $captured++;
                }
            });

        return $captured;
    }

    /**
     * @param  list<string>  $columns
     * @return Builder
     */
    private function scopedQuery(
        Connection $connection,
        string $table,
        int $userId,
        array $columns,
    ) {
        $select = array_map(static fn (string $column): string => $table.'.'.$column, $columns);
        $query = $connection->table($table)->select($select);

        $parent = self::PARENT_SCOPE[$table] ?? null;

        if ($parent === null) {
            return $query->where($table.'.user_id', $userId);
        }

        [$foreignKey, $parentTable] = $parent;

        return $query->whereIn(
            $table.'.'.$foreignKey,
            $connection->table($parentTable)->select('id')->where('user_id', $userId),
        );
    }

    // Empty for a table this build has no schema for — a module whose
    // migrations are not installed still appears in the merge rules, and
    // capturing it would fail the whole backfill on an optional feature.
    /**
     * @return list<string>
     */
    private function columnsOf(Connection $connection, string $table): array
    {
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return [];
        }

        /** @var list<string> $listing */
        $listing = $schema->getColumnListing($table);
        $columns = array_values(array_diff($listing, self::SKIPPED_COLUMNS));

        // The pk is selected separately from the emitted fields so the
        // capture loop can split it back out without re-querying.
        return $columns === [] ? [] : array_merge(['id'], $columns);
    }
}
