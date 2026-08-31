<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Exceptions\RebuildInProgressException;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\RowHistoryPolicy;

final class OpLogRebuilder
{
    // One `id IN (...)` per chunk, sized to stay well inside SQLite's bind
    // ceiling — the same reason PersistedOpLogEntries chunks its pk lookups.
    private const int REINDEX_CHUNK = 400;

    /** @var list<string> */
    private readonly array $coveredTables;

    private readonly CoveredTableOrder $tableOrder;

    private readonly PersistedOpLogEntries $persistedEntries;

    // Keyed by userId, true = lock held. Non-static because readonly classes
    // cannot have static properties; each instance tracks its own lock,
    // equivalent to a process-level guard in single-user SQLite.
    /** @var array<int, bool> */
    private array $heldLocks = [];

    /**
     * @param  DatabaseManager  $db  Raw DB access.
     * @param  OpLogReplayer  $replayer  Production replayer — re-used so rebuild equals incremental.
     * @param  MergeRulesRegistry  $registry  Source of covered-table list (config-driven).
     * @param  list<string>|null  $coveredTables  Override covered tables (null = derive from registry).
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OpLogReplayer $replayer,
        MergeRulesRegistry $registry,
        ?array $coveredTables = null,
        ?CoveredTableOrder $tableOrder = null,
        private readonly ?SearchIndexWriterContract $searchWriter = null,
        ?PersistedOpLogEntries $persistedEntries = null,
    ) {
        $this->persistedEntries = $persistedEntries ?? new PersistedOpLogEntries($db);
        // Built here when absent rather than left null. The container leaves
        // this optional parameter unresolved, and the null fallback was plain
        // registry order — which lists import_runs before transactions, so
        // every rebuild deleted a parent its children still referenced.
        $this->tableOrder = $tableOrder ?? new CoveredTableOrder($this->db, $registry);

        // Derives the covered-table list from the registry so it stays
        // config-driven; caller can override for testing partial subsets.
        /** @var list<string> $tables */
        $tables = $coveredTables ?? array_keys($registry->rules());
        $this->coveredTables = $tables;
    }

    // Full-rebuild: snapshot triggers -> drop -> delete -> replay -> restore,
    // all inside one DB transaction. If ANY step throws, the transaction
    // rolls back — triggers and data are restored to their pre-rebuild state automatically.
    /**
     * @param  int  $userId  Rebuild is scoped to this user only (guard on every DELETE).
     *
     * @throws \Throwable Re-throws after lock release if the transaction fails.
     */
    public function rebuild(int $userId): void
    {
        $this->acquireMaintenanceLock($userId);

        try {
            $this->db->connection()->transaction(function () use ($userId): void {
                $triggerSnapshots = $this->snapshotTriggers();

                $this->dropTriggers($triggerSnapshots);
                $this->deleteReplayableRows($userId);

                // The production replayer, so rebuild equals incremental —
                // injected without a SearchIndexWriterContract so FTS writes
                // are suppressed here, and handed the whole log, which is
                // already every op of every row it names.
                $this->replayer->replay($this->loadEntries($userId), $userId, RowHistoryPolicy::AsGiven);

                $this->restoreTriggers($triggerSnapshots);
            });

            // Outside the transaction, because FTS writes were suppressed
            // inside it and nothing put them back: every rebuilt row lost its
            // search doc, so search quietly stopped finding real transactions.
            $this->reindex($userId);
        } finally {
            $this->releaseMaintenanceLock($userId);
        }
    }

    // Re-derives the full-text index for the rows the rebuild just replayed —
    // the rows the op log NAMES, not every transaction the reader owns. A
    // ledger of a hundred thousand rows reindexed all of them for a delta
    // naming three thousand, and that sweep was 91% of the rebuild's queries.
    /**
     * @link ../../../../.docs/features/sync/op-log-merge-rules.md#what-a-rebuild-reindexes
     */
    private function reindex(int $userId): void
    {
        if ($this->searchWriter === null) {
            return;
        }

        foreach (array_chunk($this->replayedTransactionIds($userId), self::REINDEX_CHUNK) as $chunk) {
            $surviving = $this->survivingIds($userId, $chunk);

            foreach ($chunk as $transactionId) {
                // A row the replay tombstoned is gone from `transactions`, and
                // upsertForTransaction() returns silently on a missing row —
                // so its search doc outlived it and kept answering queries.
                $this->reindexOne($transactionId, $userId, isset($surviving[$transactionId]));
            }
        }
    }

    private function reindexOne(int $transactionId, int $userId, bool $survives): void
    {
        if ($this->searchWriter === null) {
            return;
        }

        try {
            if ($survives) {
                $this->searchWriter->upsertForTransaction($transactionId, $userId);

                return;
            }

            $this->searchWriter->deleteForTransaction($transactionId, $userId);
        } catch (\Throwable) {
            // One unindexable row must not stop the rest being indexed:
            // a stale index recovers, a half-indexed sweep does not.
        }
    }

    // Every transaction the replay could have changed: an op names its row,
    // and a row no op names was not touched, so its doc is still true.
    /**
     * @return list<int>
     */
    private function replayedTransactionIds(int $userId): array
    {
        $named = $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', 'transactions')
            ->distinct()
            ->pluck('pk');

        $ids = [];

        foreach ($named as $pk) {
            if (is_numeric($pk)) {
                $ids[] = (int) $pk;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function survivingIds(int $userId, array $ids): array
    {
        $surviving = [];

        foreach ($this->db->connection()->table('transactions')->where('user_id', $userId)->whereIn('id', $ids)->pluck('id') as $id) {
            if (is_numeric($id)) {
                $surviving[(int) $id] = true;
            }
        }

        return $surviving;
    }

    /**
     * @param  list<array{name: string, sql: string|null}>  $triggerSnapshots
     */
    private function dropTriggers(array $triggerSnapshots): void
    {
        foreach ($triggerSnapshots as $trigger) {
            $this->db->connection()->statement(
                "DROP TRIGGER IF EXISTS {$trigger['name']}",
            );
        }
    }

    /**
     * @param  list<array{name: string, sql: string|null}>  $triggerSnapshots
     */
    private function restoreTriggers(array $triggerSnapshots): void
    {
        foreach ($triggerSnapshots as $trigger) {
            if (is_string($trigger['sql'])) {
                $this->db->connection()->statement($trigger['sql']);
            }
        }
    }

    // Delete ONLY the rows the op-log can recreate (a CreateRow op for this
    // user). Rows predating capture have no create op and are preserved so
    // their SET ops replay on top; imports now DO carry create ops. FK-safe
    // order avoids aborting on foreign_keys=ON.
    private function deleteReplayableRows(int $userId): void
    {
        foreach ($this->fkSafeDeletionOrder() as $table) {
            $createdPks = $this->createdRowPks($userId, $table);

            if ($createdPks === []) {
                continue;
            }

            $this->db->connection()
                ->table($table)
                ->where('user_id', $userId)
                ->whereIn('id', $createdPks)
                ->delete();
        }
    }

    // Load the full op-log for this user, HLC-sorted, then map rows back to
    // OpLogEntry objects.
    /**
     * @return list<OpLogEntry>
     */
    private function loadEntries(int $userId): array
    {
        return $this->persistedEntries->forUser($userId);
    }

    // Children before parents, derived from the live foreign keys rather
    // than a hand-maintained list that drifts as tables are added.
    /**
     * @return list<string>
     */
    private function fkSafeDeletionOrder(): array
    {
        $covered = $this->coveredTables;

        return array_values(array_filter(
            $this->tableOrder->deletionOrder(),
            static fn (string $table): bool => in_array($table, $covered, true),
        ));
    }

    // The only rows safe to DELETE before replay — the CreateRow ops will
    // faithfully recreate them. Rows without one are import-created and
    // immutable, preserved so their SET ops replay on top.
    /**
     * @return list<int>
     */
    private function createdRowPks(int $userId, string $table): array
    {
        $rows = $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->distinct()
            ->pluck('pk');

        /** @var list<int> $pks */
        $pks = [];

        foreach ($rows as $pk) {
            if (is_numeric($pk)) {
                $pks[] = (int) $pk;
            }
        }

        return $pks;
    }

    /**
     * @return list<array{name: string, sql: string|null}>
     */
    private function snapshotTriggers(): array
    {
        if ($this->coveredTables === []) {
            return [];
        }

        // coveredTables is config-driven, not user input, so string-building
        // it directly into SQL below is safe.
        $escapedTables = array_map(
            static fn (string $t): string => "'".str_replace("'", "''", $t)."'",
            $this->coveredTables,
        );
        $inClause = implode(',', $escapedTables);

        $rows = $this->db->connection()->select(
            "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ({$inClause})",
        );

        /** @var list<array{name: string, sql: string|null}> $snapshots */
        $snapshots = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $vars = get_object_vars($row);
            $name = is_string($vars['name'] ?? null) ? $vars['name'] : null;

            if ($name === null || $name === '') {
                continue;
            }

            $snapshots[] = [
                'name' => $name,
                'sql' => is_string($vars['sql'] ?? null) ? $vars['sql'] : null,
            ];
        }

        return $snapshots;
    }

    /**
     * @throws \RuntimeException If a rebuild is already in progress for this user.
     */
    private function acquireMaintenanceLock(int $userId): void
    {
        if (isset($this->heldLocks[$userId]) && $this->heldLocks[$userId]) {
            throw RebuildInProgressException::forUser($userId);
        }

        $this->heldLocks[$userId] = true;
    }

    private function releaseMaintenanceLock(int $userId): void
    {
        unset($this->heldLocks[$userId]);
    }
}
