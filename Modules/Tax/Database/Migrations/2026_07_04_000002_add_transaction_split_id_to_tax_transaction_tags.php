<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds a nullable `transaction_split_id` FK to `tax_transaction_tags` so a
 * tax-deductible tag can be scoped to one leg of a split transaction, not
 * just the whole parent (Phase 13.1 D-06a — "Full per-leg tax (correct)").
 *
 * The old `unique(user_id, transaction_id)` constraint is reworked to
 * `unique(user_id, transaction_id, transaction_split_id)`. SQLite treats
 * every NULL as a distinct value for uniqueness purposes, so the existing
 * whole-transaction tag rows (transaction_split_id IS NULL) continue to
 * satisfy the new constraint without any data migration — no backfill is
 * required or performed.
 *
 * SUPERSESSION POLICY (locked default, see 13.1-04-PLAN.md interfaces):
 * once a transaction has ANY leg-scoped tag (transaction_split_id NOT NULL),
 * the whole-transaction tag row (transaction_split_id IS NULL) for that same
 * transaction stops being surfaced/exported by TaxTagQuery/TaxYearQuery. It
 * is NOT deleted here or anywhere else — this migration performs a pure
 * additive schema change.
 *
 * Split into two separate Blueprint closures (FK add, then constraint
 * rework) mirroring the plan's guidance for SQLite grammar safety — see
 * 2026_05_15_010002_add_pair_transaction_id_to_transactions.php for the
 * closest existing "nullable FK add + constraint change" precedent.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->foreignId('transaction_split_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('transaction_splits')
                ->cascadeOnDelete();
        });

        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'transaction_id']);
            $table->unique(['user_id', 'transaction_id', 'transaction_split_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'transaction_id', 'transaction_split_id']);
            $table->unique(['user_id', 'transaction_id']);
        });

        $this->schema()->table('tax_transaction_tags', static function (Blueprint $table): void {
            $table->dropForeign(['transaction_split_id']);
            $table->dropColumn('transaction_split_id');
        });
    }
};
