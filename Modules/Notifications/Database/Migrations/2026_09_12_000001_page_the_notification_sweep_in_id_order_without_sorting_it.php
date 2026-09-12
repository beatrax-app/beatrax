<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-eight-other-tables-the-pass-walks
 */
return new class extends ModuleMigration
{
    // The enable-time encryption pass walks every table it rewrites with
    // `where user_id = ? and id > ? order by id`, and this is the one table
    // whose `id` is a varchar rather than the rowid — so the rowid SQLite
    // appends to an index answers nothing here and `id` must be named.
    public function up(): void
    {
        $this->schema()->table('notifications', static function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'notifications_user_id_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->table('notifications', static function (Blueprint $table): void {
            $table->dropIndex('notifications_user_id_id_index');
        });
    }
};
