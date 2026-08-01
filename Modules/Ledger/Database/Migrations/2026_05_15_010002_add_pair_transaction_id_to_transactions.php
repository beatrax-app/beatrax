<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds a self-referential nullable `pair_transaction_id` foreign key on
 * `transactions`. Paired rows (e.g., ASN→ICS bulk-iDEAL settlement;
 * PayPal→ASN sweep) carry pointers at each other so the dashboard can
 * count them as transfers rather than as income or expense.
 *
 * The FK action is `ON DELETE SET NULL`: when a partner row is hard-
 * deleted the survivor stays in the ledger as a regular row instead of
 * orphan-pointing into the void. Cross-currency pairs are valid, so
 * there is no DB-layer CHECK on amount-sum; the listener that writes
 * pairs owns the equal-and-opposite invariant.
 *
 * The column is NOT part of the v3 fingerprint tuple — adding it does
 * not require a fingerprint version bump or a re-derive pass.
 *
 * A partial index over the unpaired-transfer subset keeps the listener
 * hot-path cheap (the listener queries by user_id + account_id +
 * booked_at while filtering on `pair_transaction_id IS NULL AND
 * type IN ('transfer_out', 'transfer_in')`).
 */
return new class extends ModuleMigration
{
    /**
     * Memoised DatabaseManager handle. Anonymous migrations cannot
     * receive constructor injection (Laravel's migrator instantiates
     * them with no arguments), so the DatabaseManager is resolved once
     * from the container at the migration boundary and cached for the
     * duration of the up/down call rather than re-resolved by every
     * helper method.
     */
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            // Self-referential FK so a paired row points to its partner.
            // ON DELETE SET NULL preserves the surviving row's existence
            // when a partner is hard-deleted — the orphan stays in the
            // ledger as a regular row rather than vanishing.
            $table->foreignId('pair_transaction_id')
                ->nullable()
                ->after('settled_amount_minor')
                ->constrained('transactions')
                ->nullOnDelete();
        });

        // Partial index for the listener hot-path lookup. The listener
        // filter is:
        //   WHERE user_id = ? AND account_id = ? AND amount_minor = ?
        //     AND currency = ? AND booked_at BETWEEN ? AND ?
        //     AND pair_transaction_id IS NULL
        //     AND type IN ('transfer_out', 'transfer_in')
        // A partial index over the unpaired-transfer subset keeps the
        // lookup cheap even at 100k+ rows.
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
