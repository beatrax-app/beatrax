<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Search\Public\Contracts\SearchIndexRepairContract;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Exceptions\RebuildInProgressException;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\RowHistoryPolicy;
use Modules\Sync\Internal\Merge\SearchIndexRefresher;
use Psr\Log\LoggerInterface;

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
        private readonly ?LoggerInterface $log = null,
        // Last, and named at every call site: the positional callers in the
        // suite pass eight arguments, so a parameter added in the middle
        // silently becomes someone else's logger.
        private readonly ?SearchIndexRepairContract $searchRepairs = null,
    ) {
        $this->persistedEntries = $persistedEntries ?? new PersistedOpLogEntries($db);
        // Built here when absent rather than left null. The container leaves
        // this optional parameter unresolved, and the null fallback was plain
        // registry order — which lists import_runs before transactions, so
        // every rebuild deleted a parent its children still referenced.
        $this->tableOrder = $tableOrder ?? new CoveredTableOrder($this->db, $registry, $log);

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

                // Deferred to the commit, not disabled: the delete takes every
                // row the log can recreate, and a derived child the log does
                // not carry still references one. Ordering cannot help -- the
                // child is not in the order, because nothing captures it.
                $this->db->connection()->unprepared('PRAGMA defer_foreign_keys = ON');

                $this->dropTriggers($triggerSnapshots);
                $removed = $this->deleteReplayableRows($userId);
                $quarantineMark = $this->quarantineMark($userId);

                // The production replayer, so rebuild equals incremental —
                // injected without a SearchIndexWriterContract so FTS writes
                // are suppressed here, and handed the whole log, which is
                // already every op of every row it names.
                $this->replayer->replay($this->loadEntries($userId), $userId, RowHistoryPolicy::AsGiven);

                $this->verifyRestored($removed, $userId, $quarantineMark);

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
        } catch (\Throwable $e) {
            // One unindexable row must not stop the rest being indexed:
            // a stale index recovers, a half-indexed sweep does not.

            // Owed to the same queue the merge path uses, so the recovery is
            // the drain rather than a rebuild nobody has a reason to run
            // again. Until it drains the row is findable under words it no
            // longer holds, or not findable at all.

            // The same sentence and recovery the merge path reports, and
            // wrapped for the same reason it is: one failure, one route each.
            try {
                $this->searchRepairs?->owe($userId, $transactionId);
            } catch (\Throwable) {
                // Same disk as the index that just failed; the warning below
                // is then the only account, which is the state this replaces.
            }

            try {
                $this->log?->warning(SearchIndexRefresher::STALE, [
                    'table' => 'transactions',
                    'pk' => (string) $transactionId,
                    'ftsOperation' => $survives ? 'upsert' : 'delete',
                    'userId' => $userId,
                    'recoverWith' => $this->searchRepairs === null ? 'search:reindex' : 'the repair queue, drained on the next unlocked request',
                    ...SafeExceptionContext::describe($e),
                ]);
            } catch (\Throwable) {
                // A logger failing on a full disk must not take a rebuild down.
            }
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
                sprintf('DROP TRIGGER IF EXISTS %s', $trigger['name']),
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
    /**
     * @return array<string, list<int>> the ids actually removed, per table
     */
    private function deleteReplayableRows(int $userId): array
    {
        $removed = [];

        foreach ($this->fkSafeDeletionOrder() as $table) {
            $createdPks = $this->createdRowPks($userId, $table);

            if ($createdPks === []) {
                continue;
            }

            $query = $this->db->connection()
                ->table($table)
                ->where('user_id', $userId)
                ->whereIn('id', $createdPks);

            // Read before the delete, not after: the check at the end compares
            // what was actually here against what came back, and the log names
            // creates for rows this device never stored.
            $here = self::asIds($query->clone()->pluck('id'));

            $query->delete();

            if ($here !== []) {
                $removed[$table] = $here;
            }
        }

        return $removed;
    }

    // Every row the delete took, back under its own id or under one this
    // device minted for it. Measured on a paired install: a console run holds
    // no group data key, 703 creates were quarantined as gdk_decrypt_failed,
    // and 12 accounts never came back. Only the foreign keys stopped it.
    /**
     * @param  array<string, list<int>>  $removed
     *
     * @throws RebuildWouldLoseRowsException
     */
    private function verifyRestored(array $removed, int $userId, int $quarantineMark): void
    {
        $missing = [];

        foreach ($removed as $table => $pks) {
            $gone = array_values(array_diff($pks, $this->accountedFor($table, $pks, $userId)));

            if ($gone !== []) {
                $missing[$table] = count($gone);
            }
        }

        if ($missing === []) {
            return;
        }

        throw new RebuildWouldLoseRowsException($missing, $this->quarantinedSince($userId, $quarantineMark));
    }

    // Present again, aliased to a row stored under another id, or tombstoned
    // by the log — a delete the log carries is the replay working, not a row
    // it failed to bring back.
    /**
     * @param  list<int>  $pks
     * @return list<int>
     */
    private function accountedFor(string $table, array $pks, int $userId): array
    {
        $present = self::asIds($this->db->connection()->table($table)->whereIn('id', $pks)->pluck('id'));

        $aliased = self::asIds($this->db->connection()->table('op_log_row_aliases')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->whereIn('remote_id', array_map(static fn (int $pk): string => (string) $pk, $pks))
            ->pluck('remote_id'));

        $tombstoned = self::asIds($this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::DeleteTombstone->value)
            ->whereIn('pk', array_map(static fn (int $pk): string => (string) $pk, $pks))
            ->distinct()
            ->pluck('pk'));

        return [...$present, ...$aliased, ...$tombstoned];
    }

    private function quarantineMark(int $userId): int
    {
        $max = $this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->max('id');

        return is_numeric($max) ? (int) $max : 0;
    }

    // What this replay refused, which is almost always why a row did not come
    // back. Reported beside the missing counts so the operator is not left to
    // guess between a missing key, a refused gate and a broken log.
    /**
     * @return array<string, int>
     */
    private function quarantinedSince(int $userId, int $mark): array
    {
        $reasons = [];

        foreach ($this->db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('id', '>', $mark)
            ->selectRaw('reason, count(*) as n')
            ->groupBy('reason')
            ->get() as $row) {
            $reason = is_string($row->reason ?? null) ? $row->reason : 'unknown';
            $reasons[$reason] = is_numeric($row->n ?? null) ? (int) $row->n : 0;
        }

        arsort($reasons);

        return $reasons;
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
            sprintf("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN (%s)", $inClause),
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
