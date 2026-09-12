<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-eight-other-tables-the-pass-walks
 */
return new class extends ModuleMigration
{
    // The counterparty key backfill runs inside the enable-time pass's own
    // transaction and walks this table with `where user_id = ? and id > ?
    // order by id`, off an index leading (user_id, state) — so it sorted
    // every link the user has to get id order, once per page.
    public function up(): void
    {
        $this->schema()->table('chain_links', static function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'chain_links_user_id_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->table('chain_links', static function (Blueprint $table): void {
            $table->dropIndex('chain_links_user_id_id_index');
        });
    }
};
