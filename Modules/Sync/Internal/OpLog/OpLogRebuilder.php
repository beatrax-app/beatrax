<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Exceptions\RebuildInProgressException;
use Modules\Sync\Internal\Merge\OpLogReplayer;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class OpLogRebuilder
{
    /** @var list<string> */
    private readonly array $coveredTables;

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
        private readonly MergeRulesRegistry $registry,
        ?array $coveredTables = null,
    ) {
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

                foreach ($triggerSnapshots as $trigger) {
                    $this->db->connection()->statement(
                        "DROP TRIGGER IF EXISTS {$trigger['name']}",
                    );
                }

                // Delete ONLY the rows the op-log can recreate (a CreateRow
                // op for this user) — import-created rows never enter the
                // log, so preserving them lets their SET ops replay on top
                // (see @link). FK-safe order avoids aborting on foreign_keys=ON.
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

                // Load the full op-log for this user, HLC-sorted, then map
                // rows back to OpLogEntry objects.
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
                        // A GDK-encrypted entry's value can only be decrypted
                        // with its original epoch tag — dropping this on
                        // rebuild would silently lose every sensitive-field edit.
                        gdkEpoch: is_numeric($vars['gdk_epoch'] ?? null) ? (int) $vars['gdk_epoch'] : null,
                    );
                }

                // Replay via the production replayer so rebuild equals
                // incremental; injected without a SearchIndexWriterContract
                // so FTS writes are suppressed inside this transaction.
                $this->replayer->replay($entries, $userId);

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

    // Covered tables ordered children-before-parents so a scoped DELETE
    // never removes a parent row before its FK children. Tables not listed
    // here keep their registry order, appended after the explicit set.
    /**
     * @return list<string>
     */
    private function fkSafeDeletionOrder(): array
    {
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

        foreach ($covered as $table) {
            if (! in_array($table, $ordered, true)) {
                $ordered[] = $table;
            }
        }

        return $ordered;
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
