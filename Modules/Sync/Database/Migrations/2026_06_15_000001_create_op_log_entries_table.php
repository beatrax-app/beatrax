<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

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
            // Always JSON. SQL NULL is reserved for the clear/tombstone
            // sentinel, so the JSON literal "null" must never be stored as
            // SQL NULL — the two would become indistinguishable on replay.
            $table->text('value')->nullable();
            // Split into two integer columns so SQLite compares them
            // natively and the [hlc_l, hlc_c, device_id] total order falls
            // out of the indexes below for free.
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
