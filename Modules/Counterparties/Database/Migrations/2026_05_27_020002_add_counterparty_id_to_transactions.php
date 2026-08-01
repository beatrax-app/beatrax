<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the nullable `counterparty_id` column to the transactions
 * table — the FK link the ResolveCounterpartyStage (Plan 17-05b)
 * stamps onto every row after the resolver upserts the matching
 * Counterparty.
 *
 * Deliberate omission: NO `constrained('counterparties')`. The
 * cascade is from the user side only (transactions inherit
 * cascadeOnDelete from users via the existing schema) so an orphaned
 * Counterparty row pruned by the future garbage-collector job does
 * NOT cascade-delete its history. Transactions keep their resolved
 * counterparty_id reference; the GC job nulls them out via an
 * explicit `UPDATE transactions SET counterparty_id = NULL WHERE
 * counterparty_id IN (...)` before the prune so referential integrity
 * is maintained without losing the underlying transaction rows.
 *
 * Index (user_id, counterparty_id) powers the counterparty-profile
 * page's "all transactions for this counterparty" hot-path query
 * with a user-scoped composite that survives partner-multi-user
 * separation.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('counterparty_id')->nullable()->after('category_id');
            $table->index(['user_id', 'counterparty_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'counterparty_id']);
            $table->dropColumn('counterparty_id');
        });
    }
};
