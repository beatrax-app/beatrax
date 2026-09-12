<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// SQLite rebuilds the table to add a foreign key and regenerates every index
// from PRAGMA index_list, which carries no WHERE clause — so adding
// pair_transaction_id turned this into a second copy of
// transactions_user_id_posted_at_index, and the triage count went back to
// reading the whole ledger to find the rows with no category.
/**
 * @link ../../../../.docs/architecture/a-rebuilt-table-loses-a-partial-index.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasTable('transactions')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement('DROP INDEX IF EXISTS transactions_uncategorized_idx');
        $connection->statement(
            'CREATE INDEX transactions_uncategorized_idx ON transactions(user_id, posted_at) WHERE category_id IS NULL'
        );
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement('DROP INDEX IF EXISTS transactions_uncategorized_idx');
        $connection->statement(
            'CREATE INDEX transactions_uncategorized_idx ON transactions(user_id, posted_at)'
        );
    }
};
