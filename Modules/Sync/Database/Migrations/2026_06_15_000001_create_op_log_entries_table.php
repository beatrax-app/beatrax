<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the op_log_entries table — the append-only, per-device-signed,
 * HLC-ordered op-log that is the single source of truth for all syncable
 * mutations (D-09, SYNC-01).
 *
 * Column notes:
 *   - `value` TEXT NULLABLE is the always-JSON wire column. Producers MUST
 *     call json_encode($phpValue) before writing; consumers MUST call
 *     json_decode($row->value, true) on read. PHP null on this column is
 *     reserved exclusively for the explicit clear/tombstone sentinel — the
 *     JSON literal "null" MUST NEVER be stored as SQL NULL (SE-07 fix).
 *   - `hlc_l` and `hlc_c` are stored as separate INTEGER columns so that
 *     SQLite can use native integer comparison in the indexes, giving the
 *     total HLC order [hlc_l, hlc_c, device_id] for free.
 *   - `signature` carries the hex-encoded Ed25519 detached sig of the
 *     deterministic signingPayload() produced by OpLogEntry (SE-04).
 *   - `op_type` must be one of: 'set' | 'delete_tombstone' | 'create_row'
 *     (matches OpType enum values).
 *
 * Two indexes support the replay and per-table query hot paths:
 *   - `op_log_entries_replay_idx` on (user_id, hlc_l, hlc_c, device_id)
 *     — enables HLC-ordered full-log replay for a single user in one scan.
 *   - `op_log_entries_table_pk_idx` on (user_id, table_name, pk, hlc_l, hlc_c)
 *     — enables per-table-row incremental merge queries.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('op_log_entries', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('device_id');
            $table->string('table_name');
            $table->string('pk');
            $table->string('field');
            $table->string('op_type', 32);
            $table->text('value')->nullable();
            $table->unsignedBigInteger('hlc_l');
            $table->unsignedInteger('hlc_c');
            $table->text('signature');
            $table->timestamp('recorded_at')->useCurrent();
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE INDEX op_log_entries_replay_idx ON op_log_entries (user_id, hlc_l, hlc_c, device_id)'
        );

        $connection->statement(
            'CREATE INDEX op_log_entries_table_pk_idx ON op_log_entries (user_id, table_name, pk, hlc_l, hlc_c)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('op_log_entries');
    }
};
