<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#what-the-enable-time-pass-holds-the-write-lock-for
 */
return new class extends ModuleMigration
{
    // Every bulk walk of this table pages with `where user_id = ? and id > ?
    // order by id`. The two indexes it had both lead with user_id and then
    // carry the HLC, so the planner took one and sorted the whole user's log
    // into a temp b-tree to get id order — once per chunk, not once per pass.
    public function up(): void
    {
        $this->schema()->table('op_log_entries', static function (Blueprint $table): void {
            // Declared on user_id alone: SQLite appends the rowid to every
            // index entry, and `id` IS the rowid here, so this one index
            // answers the filter and the ordering together.
            $table->index('user_id', 'op_log_entries_user_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->table('op_log_entries', static function (Blueprint $table): void {
            $table->dropIndex('op_log_entries_user_id_index');
        });
    }
};
