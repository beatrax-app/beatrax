<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/tax/tag-write-contract.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        // SQLite counts every NULL as distinct, so the compound unique index does
        // not reject two whole-transaction rows (both split id NULL) and a
        // double-clicked Tag button could double-count the deduction. A partial
        // index restores one row per whole-transaction tag.
        $this->connection()->statement(
            'CREATE UNIQUE INDEX tax_tags_whole_tx_unique ON tax_transaction_tags (user_id, transaction_id) WHERE transaction_split_id IS NULL'
        );
    }

    public function down(): void
    {
        $this->connection()->statement('DROP INDEX IF EXISTS tax_tags_whole_tx_unique');
    }

    private function connection(): Connection
    {
        return $this->db()->connection($this->getConnection());
    }
};
