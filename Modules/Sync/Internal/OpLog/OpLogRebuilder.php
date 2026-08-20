<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Exceptions\RebuildInProgressException;
use Modules\Sync\Internal\Merge\OpLogReplayer;

final class OpLogRebuilder
{
    /** @var list<string> */
    private readonly array $coveredTables;

    private readonly CoveredTableOrder $tableOrder;

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
    ) {
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

                // Replay via the production replayer so rebuild equals
                // incremental; injected without a SearchIndexWriterContract
                // so FTS writes are suppressed inside this transaction.
                $this->replayer->replay($this->loadEntries($userId), $userId);

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

    // Re-derives the full-text index for the rows the rebuild just replayed.
    // Never throws: a stale index is recoverable and must not turn a finished
    // rebuild into a failed one.
    private function reindex(int $userId): void
    {
        if ($this->searchWriter === null) {
            return;
        }

        $transactionIds = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->pluck('id');

        foreach ($transactionIds as $transactionId) {
            try {
                $this->searchWriter->upsertForTransaction((int) $transactionId, $userId);
            } catch (\Throwable) {
                // One unindexable row must not stop the rest being indexed:
                // a stale index recovers, a half-indexed sweep does not.
            }
        }
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
        $rows = $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->orderBy('hlc_l')
            ->orderBy('hlc_c')
            ->orderBy('device_id')
            ->get();

        /** @var list<OpLogEntry> $entries */
        $entries = [];

        foreach ($rows as $row) {
            $entries[] = $this->mapRowToEntry($row);
        }

        return $entries;
    }

    private function mapRowToEntry(object $row): OpLogEntry
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
    private static function normalizePk(mixed $pkRaw): int|string
    {
        if (is_numeric($pkRaw)) {
            return (int) $pkRaw;
        }

        return is_string($pkRaw) ? $pkRaw : '';
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
            ->where('op_type', 'create_row')
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
        if (isset($this->heldLocks[$userId]) && $this->heldLocks[$userId] === true) {
            throw RebuildInProgressException::forUser($userId);
        }

        $this->heldLocks[$userId] = true;
    }

    private function releaseMaintenanceLock(int $userId): void
    {
        unset($this->heldLocks[$userId]);
    }
}
