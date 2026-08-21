<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Cross-currency pairs are valid, so there is no DB-layer CHECK on amount-sum:
// the listener that writes pairs owns the equal-and-opposite invariant. The column
// is not part of the v3 fingerprint tuple, so no version bump or re-derive is needed.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            // ON DELETE SET NULL rather than cascade: when a partner row is
            // hard-deleted the survivor stays in the ledger as a regular row.
            $table->foreignId('pair_transaction_id')
                ->nullable()
                ->after('settled_amount_minor')
                ->constrained('transactions')
                ->nullOnDelete();
        });

        // Partial index over the unpaired-transfer subset, matching the pairing
        // listener's filter exactly so its hot path stays cheap at 100k+ rows.
        $this->db()->connection($this->getConnection())->statement(
            'CREATE INDEX transactions_unpaired_transfer_idx ON transactions(user_id, account_id, booked_at) '.
            "WHERE pair_transaction_id IS NULL AND type IN ('transfer_out', 'transfer_in')"
        );
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropForeign(['pair_transaction_id']);
            $table->dropColumn('pair_transaction_id');
        });
        $this->db()->connection($this->getConnection())->statement(
            'DROP INDEX IF EXISTS transactions_unpaired_transfer_idx'
        );
    }
};
