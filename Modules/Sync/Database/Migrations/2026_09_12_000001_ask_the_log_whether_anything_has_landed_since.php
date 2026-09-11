<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md#a-window-stamped-against-a-key-cannot-bound-a-hold-a-key-never-held
 */
return new class extends ModuleMigration
{
    private const string INDEX = 'op_log_entries_recorded_idx';

    // The recovery pass now asks whether this device recorded anything since
    // its last pass. Off the existing (user_id, hlc_l, ...) index that reads
    // every entry the user has and every row behind them, and the steady-state
    // answer is NO, which is the walk that does not stop early.
    public function up(): void
    {
        if (! $this->schema()->hasTable('op_log_entries')) {
            return;
        }

        $this->db()->connection($this->getConnection())->statement(
            'CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON op_log_entries (user_id, recorded_at)'
        );
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable('op_log_entries')) {
            return;
        }

        $this->db()->connection($this->getConnection())->statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
