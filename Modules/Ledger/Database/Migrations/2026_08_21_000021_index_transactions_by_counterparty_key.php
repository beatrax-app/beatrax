<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// counterparty_normalized sits 7th in the fingerprint unique index, which no
// lookup can seek on, so every read keyed by merchant scanned the user's whole
// transactions partition — once per distinct merchant, each recurring sweep.
return new class extends ModuleMigration
{
    private const INDEX = 'transactions_user_counterparty_posted_idx';

    public function up(): void
    {
        if (! $this->schema()->hasTable('transactions')) {
            return;
        }

        // posted_at trails the two equality columns so the newest transaction
        // naming a merchant is the first row of a backwards scan, not a sort.
        $this->db()->connection($this->getConnection())->statement(
            'CREATE INDEX IF NOT EXISTS '.self::INDEX
            .' ON transactions(user_id, counterparty_normalized, posted_at)'
        );
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())->statement(
            'DROP INDEX IF EXISTS '.self::INDEX
        );
    }
};
