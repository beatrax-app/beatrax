<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('deferred_op_captures', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');

            // Coordinates, never values: the row this mutation touched and the
            // column of it, so the drain can re-read the current truth once a
            // key is in reach. op_log_entries already carries these three in
            // the clear, so nothing is disclosed here that the log does not.
            $table->string('table_name');
            $table->string('pk');
            $table->string('field');
            $table->string('op_kind');

            // The one quantity a re-read cannot recover: a g_counter column
            // stores the merged total across every device, so this device's
            // own contribution is gone the moment it lands there.
            $table->integer('delta')->nullable();

            $table->string('captured_at');

            // One entry per coordinate. A locked device touching one field a
            // thousand times owes its peer one op, and the drain's cost has to
            // follow the number of rows changed rather than the number of writes.
            $table->unique(['user_id', 'table_name', 'pk', 'field', 'op_kind'], 'deferred_op_captures_coordinate');

            // The drain reads in insertion order, which is capture order: the
            // create of a row has to reach a peer before the sets that follow it.
            $table->index(['user_id', 'id'], 'deferred_op_captures_user_order');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('deferred_op_captures');
    }
};
