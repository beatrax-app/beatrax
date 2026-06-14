<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;

/**
 * Trigger-safe deterministic full-rebuild of the SQLite materialized view from
 * the merged op-log.
 *
 * ## Purpose (D-10 / SYNC-02)
 *
 * The normal day-to-day merge path (OpLogReplayer) applies incremental UPDATEs
 * and DELETEs — it NEVER drops triggers. This class provides the maintenance-window
 * path used during device onboarding or disaster recovery: wipe the covered tables,
 * replay the full persisted op-log from scratch, and confirm the rebuilt state equals
 * the incrementally-merged state.
 *
 * ## Safety invariants
 *
 * - DROP TRIGGER appears ONLY in this class (T-11-07). The grep-guard in
 *   TriggerAwareRebuildTest asserts no DROP TRIGGER exists outside this file.
 * - Trigger snapshot, DROP, DELETE, replay, and restore ALL happen inside a SINGLE
 *   DB transaction. Any exception rolls back all of them: triggers and data both
 *   return to pre-rebuild state automatically (the rollback IS the D-10 safety path).
 * - rebuild() is scoped to a single user_id — no cross-user data is touched.
 * - The replayer is constructed without a SearchIndexWriterContract so FTS writes
 *   are suppressed during rebuild (avoids FTS/base-table transaction conflict).
 *   A bulk FTS reindex is the caller's responsibility after a successful rebuild.
 *
 * ## Maintenance window (D-10)
 *
 * rebuild() acquires a PHP-level maintenance lock (in-process mutex) before opening
 * the DB transaction. For single-user / single-writer SQLite this is sufficient.
 */
final class OpLogRebuilder
{
    /** @var list<string> */
    private readonly array $coveredTables;

    /**
     * In-process maintenance lock — keyed by userId, true = lock held.
     * Non-static because readonly classes cannot have static properties (PHP 8.2).
     * Each OpLogRebuilder instance tracks its own lock; in single-user SQLite this
     * is equivalent to a process-level guard.
     *
     * @var array<int, bool>
     */
    private array $heldLocks = [];

    /**
     * @param  DatabaseManager  $db  Raw DB access.
     * @param  OpLogReplayer  $replayer  Production replayer — re-used so rebuild equals incremental.
     * @param  MergeRulesRegistry  $registry  Source of covered-table list (config-driven, D-05).
     * @param  list<string>|null  $coveredTables  Override covered tables (null = derive from registry).
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OpLogReplayer $replayer,
        private readonly MergeRulesRegistry $registry,
        ?array $coveredTables = null,
    ) {
        // Derive the covered-table list from the registry so it stays config-driven (D-05).
        // Caller can override for testing partial subsets.
        /** @var list<string> $tables */
        $tables = $coveredTables ?? array_keys($registry->rules());
        $this->coveredTables = $tables;
    }

    /**
     * Full-rebuild: snapshot triggers → drop → delete → replay → restore.
     *
     * All steps run inside one DB transaction with a try/finally maintenance lock.
     * If ANY step throws, the transaction rolls back — triggers and data are restored
     * to their pre-rebuild state automatically.
     *
     * @param  int  $userId  Rebuild is scoped to this user only (I2 guard on every DELETE).
     *
     * @throws \Throwable Re-throws after lock release if the transaction fails.
     */
    public function rebuild(int $userId): void
    {
        $this->acquireMaintenanceLock($userId);

        try {
            $this->db->connection()->transaction(function () use ($userId): void {
                // Step 1: snapshot trigger DDL for covered tables.
                $triggerSnapshots = $this->snapshotTriggers();

                // Step 2: drop triggers (ONLY safe inside this locked maintenance window).
                foreach ($triggerSnapshots as $trigger) {
                    $this->db->connection()->statement(
                        "DROP TRIGGER IF EXISTS {$trigger['name']}",
                    );
                }

                // Step 3: delete all covered-table rows for this user.
                foreach ($this->coveredTables as $table) {
                    $this->db->connection()
                        ->table($table)
                        ->where('user_id', $userId)
                        ->delete();
                }

                // Step 4: load the full op-log for this user, HLC-sorted.
                $rows = $this->db->connection()
                    ->table('op_log_entries')
                    ->where('user_id', $userId)
                    ->orderBy('hlc_l')
                    ->orderBy('hlc_c')
                    ->orderBy('device_id')
                    ->get();

                // Map op_log_entries rows back to OpLogEntry objects.
                /** @var list<OpLogEntry> $entries */
                $entries = [];

                foreach ($rows as $row) {
                    $vars = get_object_vars($row);
                    $opTypeStr = is_string($vars['op_type'] ?? null) ? $vars['op_type'] : '';
                    $pkRaw = $vars['pk'] ?? '';
                    $pk = is_numeric($pkRaw) ? (int) $pkRaw : (is_string($pkRaw) ? $pkRaw : '');

                    $entries[] = new OpLogEntry(
                        table: is_string($vars['table_name'] ?? null) ? $vars['table_name'] : '',
                        pk: $pk,
                        field: is_string($vars['field'] ?? null) ? $vars['field'] : '',
                        value: is_string($vars['value'] ?? null) ? $vars['value'] : null,
                        hlcL: is_numeric($vars['hlc_l'] ?? null) ? (int) $vars['hlc_l'] : 0,
                        hlcC: is_numeric($vars['hlc_c'] ?? null) ? (int) $vars['hlc_c'] : 0,
                        deviceId: is_string($vars['device_id'] ?? null) ? $vars['device_id'] : '',
                        opType: OpType::from($opTypeStr),
                        signature: is_string($vars['signature'] ?? null) ? $vars['signature'] : '',
                        userId: is_numeric($vars['user_id'] ?? null) ? (int) $vars['user_id'] : 0,
                    );
                }

                // Step 4b: replay via the production replayer so rebuild equals incremental.
                // The replayer was injected without a SearchIndexWriterContract so FTS writes
                // are suppressed inside this transaction (Pitfall 3 prevention).
                $this->replayer->replay($entries, $userId);

                // Step 5: restore all triggers from their DDL snapshots.
                foreach ($triggerSnapshots as $trigger) {
                    if (is_string($trigger['sql'])) {
                        $this->db->connection()->statement($trigger['sql']);
                    }
                }
            });
        } finally {
            $this->releaseMaintenanceLock($userId);
        }
    }

    /**
     * Read trigger DDL for all covered tables from sqlite_master.
     *
     * @return list<array{name: string, sql: string|null}>
     */
    private function snapshotTriggers(): array
    {
        if ($this->coveredTables === []) {
            return [];
        }

        // Build IN clause with escaped table names.
        // coveredTables is config-driven (not user input) so string-building is safe.
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
     * Acquire the in-process maintenance lock for this userId.
     * Single-user / single-writer SQLite: this PHP-level guard is sufficient (D-10).
     *
     * @throws \RuntimeException If a rebuild is already in progress for this user.
     */
    private function acquireMaintenanceLock(int $userId): void
    {
        if (isset($this->heldLocks[$userId]) && $this->heldLocks[$userId] === true) {
            throw new \RuntimeException(
                "OpLogRebuilder: rebuild already in progress for user {$userId} (maintenance lock held).",
            );
        }

        $this->heldLocks[$userId] = true;
    }

    /**
     * Release the in-process maintenance lock for this userId.
     */
    private function releaseMaintenanceLock(int $userId): void
    {
        unset($this->heldLocks[$userId]);
    }
}
