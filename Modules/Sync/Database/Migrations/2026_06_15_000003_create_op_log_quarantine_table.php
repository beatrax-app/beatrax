<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the op_log_quarantine table — structured merge-anomaly records
 * written whenever the merge engine skips or rejects an op-log entry
 * (D-07, SYNC-01).
 *
 * Design notes:
 *   - The engine NEVER throws on a bad entry; it writes a quarantine row
 *     and continues (replay stays deterministic).
 *   - `reason` documents the skip cause — one of:
 *       'forged_signature'     — Ed25519 verify failed (T-10-01 gate)
 *       'cross_user'           — entry.userId !== replay userId (T-10-02 I1)
 *       'incomplete_create_row'— CreateRow op missing required NOT NULL cols
 *       'missing_device_key'   — no public key in the device-key map for device_id
 *       'unknown_table'        — table not in MergeRulesRegistry
 *       'strategy_error'       — MergeStrategyInterface::resolve() threw
 *   - `op_entry_id` is nullable because some entries (e.g. cross_user) may
 *     be quarantined before they are persisted to op_log_entries.
 *   - `raw_value` captures the original op value that was rejected.
 *   - `user_id` is required on every row — the SyncHealthPage MUST always
 *     filter by user_id (Pitfall 4 — no BelongsToUser global scope here).
 *
 * One index supports the sync-health page hot path:
 *   - `op_log_quarantine_user_idx` on (user_id, created_at) DESC — enables
 *     per-user recent-skip queries without a full-table scan.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('op_log_quarantine', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('op_entry_id')->nullable();
            $table->string('table_name');
            $table->string('pk');
            $table->string('device_id');
            $table->string('reason');
            $table->unsignedBigInteger('hlc_l')->nullable();
            $table->unsignedInteger('hlc_c')->nullable();
            $table->text('raw_value')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $connection = $this->db()->connection($this->getConnection());

        // WR-04: index ordering matches the documented intent and the
        // ->orderByDesc('created_at') hot-path query (DESC on created_at).
        $connection->statement(
            'CREATE INDEX op_log_quarantine_user_idx ON op_log_quarantine (user_id, created_at DESC)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('op_log_quarantine');
    }
};
