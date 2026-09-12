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
    // order by id`. Every index it carries leads with user_id and then the
    // state or the cluster key, so the planner sorted to get the id order.
    public function up(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'recurring_series_user_id_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->dropIndex('recurring_series_user_id_id_index');
        });
    }
};
