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
    // `where user_id = ? and id > ? order by id`. Every index these carry
    // leads with user_id and then something else, so the planner sorted the
    // whole user's table for the id order — once per page, not once per pass.
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            // Named, not bare `user_id`: a one-column index is narrow enough
            // that the planner prefers it to transactions_uncategorized_idx,
            // and the triage count goes back to reading the whole ledger.
            $table->index(['user_id', 'id'], 'transactions_user_id_id_index');
        });

        $this->schema()->table('transaction_splits', static function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'transaction_splits_user_id_id_index');
        });

        // Walked by the counterparty key backfill, which runs inside that same
        // transaction and pages the same way.
        $this->schema()->table('merchants', static function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'merchants_user_id_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropIndex('transactions_user_id_id_index');
        });

        $this->schema()->table('transaction_splits', static function (Blueprint $table): void {
            $table->dropIndex('transaction_splits_user_id_id_index');
        });

        $this->schema()->table('merchants', static function (Blueprint $table): void {
            $table->dropIndex('merchants_user_id_id_index');
        });
    }
};
