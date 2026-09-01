<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        // Two devices that signed up independently seed the same reference row
        // under different autoincrement ids. The peer's create then collides
        // with the local twin on another unique index, and every child naming
        // the peer's id is orphaned. This is where the two ids are reconciled.
        $this->schema()->create('op_log_row_aliases', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('table_name');

            // Peer-scoped: two devices both count from 1, so the same remote id
            // names a different row on each of them.
            $table->string('device_id');
            $table->string('remote_id');
            $table->string('local_id');
            $table->string('created_at');

            $table->unique(['user_id', 'table_name', 'device_id', 'remote_id'], 'op_log_row_aliases_peer_row_unique');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('op_log_row_aliases');
    }
};
