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
    // `where user_id = ? and id > ? order by id`. Both indexes here lead
    // with user_id and then something else, so the planner sorted the whole
    // merchant list into a temp b-tree for the id order, once per page.
    public function up(): void
    {
        $this->schema()->table('counterparties', static function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'counterparties_user_id_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->table('counterparties', static function (Blueprint $table): void {
            $table->dropIndex('counterparties_user_id_id_index');
        });
    }
};
