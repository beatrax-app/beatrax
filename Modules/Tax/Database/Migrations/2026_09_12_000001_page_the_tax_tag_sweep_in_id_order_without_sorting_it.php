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
    // `where user_id = ? and id > ? order by id`. Every index here leads
    // with user_id and then something else, so the planner sorted the whole
    // tag list into a temp b-tree for the id order, once per page.
    public function up(): void
    {
        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'tax_transaction_tags_user_id_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->dropIndex('tax_transaction_tags_user_id_id_index');
        });
    }
};
