<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;

// The fingerprint indexes all lead with the tuple's own columns, so looking a
// row up by the reference it arrived under scanned the reader's whole history
// once per imported row. Not unique: two rows may legitimately share a
// reference, and the lookup answers "none" rather than guessing between them.
return new class extends ModuleMigration
{
    private const string INDEX = 'transactions_account_source_ref_idx';

    public function up(): void
    {
        if (! $this->schema()->hasTable('transactions')) {
            return;
        }

        $this->connection()->statement(
            'CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON transactions(user_id, account_id, source_ref)'
        );
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable('transactions')) {
            return;
        }

        $this->connection()->statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    private function connection(): Connection
    {
        return $this->db()->connection($this->getConnection());
    }
};
