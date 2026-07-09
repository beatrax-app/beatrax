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
 * path used during device onboarding or disaster recovery: drop covered-table
 * rows the op-log can recreate, replay the full persisted op-log from scratch,
 * and end with the rebuilt state equal to the incrementally-merged state.
 *
 * ## Rebuild deletes only op-log-CREATED rows (CR-04)
 *
 * The op-log captures post-import USER edits only — import-created rows are
 * immutable and never enter the log (SYNC-03). A from-scratch "DELETE all then
 * replay" would wipe import rows that the SET-only log cannot recreate, so the
 * rebuilt DB would NOT equal the incremental state. Instead rebuild() deletes
 * only rows that have a `create_row` op in the log (the CreateRow ops recreate
 * them) and PRESERVES import rows so their SET ops replay on top. Tombstones in
 * the log delete rows during replay. The net result equals the incremental path.
 *
 * ## Safety invariants
 *
 * - DROP TRIGGER in production code is confined to this class. This is enforced
 *   by the arch test in OpLogRebuilderTest ("no DROP TRIGGER outside the
 *   rebuilder and the trigger-owning migrations"), NOT by any assertion in
 *   TriggerAwareRebuildTest (an earlier docblock claimed a grep-guard there that
 *   never existed — corrected per CR-04 / IN-01).
 * - Trigger snapshot, DROP, DELETE, replay, and restore ALL happen inside a SINGLE
 *   DB transaction. Any exception rolls back all of them: triggers and data both
 *   return to pre-rebuild state automatically (the rollback IS the D-10 safety path).
 * - Deletion runs in FK-safe order (children before parents) so PRAGMA
 *   foreign_keys=ON cannot abort the rebuild mid-way.
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

                // Step 3: delete ONLY the rows the op-log can recreate — i.e. rows
                // that have a CreateRow op in the log for this user (CR-04).
                //
                // The op-log captures post-import USER edits only; import-created
                // rows are immutable and never enter the log (SYNC-03). A naive
                // "DELETE every covered-table row then replay" would wipe those
                // import rows and the SET-only log could not recreate them, leaving
                // the rebuilt DB empty for every imported row — so rebuild would NOT
                // equal the incremental state. Instead we delete only op-log-CREATED
                // rows (which the CreateRow ops will faithfully recreate) and PRESERVE
                // import rows so their SET ops replay on top of them, exactly as the
                // incremental path does. Tombstones in the log still delete rows
                // during the replay below.
                //
                // Deletion runs in FK-safe order: children before parents. With
                // PRAGMA foreign_keys=ON a raw DELETE that removed a parent before
                // its children could abort the whole rebuild.
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
                        // Phase 14 (CRYPT-01): a GDK-encrypted entry's value can only be
                        // decrypted with its original epoch tag — dropping this on rebuild
                        // would silently lose every sensitive-field edit ever made.
                        gdkEpoch: is_numeric($vars['gdk_epoch'] ?? null) ? (int) $vars['gdk_epoch'] : null,
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
     * Covered tables ordered children-before-parents so a scoped DELETE never
     * removes a parent row before its FK children (CR-04). Tables not listed
     * here keep their registry order, appended after the explicitly-ordered set.
     *
     * The explicit ordering encodes the known FK dependencies among covered
     * tables: category_budgets -> categories, tax_transaction_tags ->
     * transactions, merchant_aliases/merchant_memories are leaf-ish. accounts is
     * a parent of transactions but is delete_wins=false (never tombstoned) and
     * is included for completeness so a CreateRow-driven rebuild deletes children
     * first.
     *
     * @return list<string>
     */
    private function fkSafeDeletionOrder(): array
    {
        // Children first, parents last.
        $preferred = [
            'tax_transaction_tags',
            'category_budgets',
            'transactions',
            'categorization_rules',
            'merchant_aliases',
            'merchant_memories',
            'counterparties',
            'pots',
            'goals',
            'categories',
            'accounts',
        ];

        $covered = $this->coveredTables;

        /** @var list<string> $ordered */
        $ordered = [];

        foreach ($preferred as $table) {
            if (in_array($table, $covered, true)) {
                $ordered[] = $table;
            }
        }

        // Append any covered table not in the preferred list (config-driven, D-05).
        foreach ($covered as $table) {
            if (! in_array($table, $ordered, true)) {
                $ordered[] = $table;
            }
        }

        return $ordered;
    }

    /**
     * Primary keys of rows that the op-log can recreate for a table — i.e. rows
     * that have at least one `create_row` op in the log for this user (CR-04).
     *
     * These are the only rows safe to DELETE before replay: the CreateRow ops
     * will faithfully recreate them. Rows without a CreateRow op are import-created
     * and immutable; they are preserved so their SET ops replay on top.
     *
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
