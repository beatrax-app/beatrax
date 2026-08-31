<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('sync_backfill_state', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();

            // Where the walk got to: the covered table being read and the
            // highest primary key already committed inside it. Both null
            // means the walk has not started, which is also how a fresh
            // capture restarts one that a completed run had closed.
            $table->string('cursor_table')->nullable();
            $table->string('cursor_pk')->nullable();

            $table->unsignedInteger('captured')->default(0);
            $table->string('started_at');

            // Null while rows are still owed. The resume driver reads exactly
            // this column, so a capture interrupted by a max-execution-time
            // fatal is picked up by the next request rather than restarted.
            $table->string('completed_at')->nullable();
            $table->string('updated_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('sync_backfill_state');
    }
};
