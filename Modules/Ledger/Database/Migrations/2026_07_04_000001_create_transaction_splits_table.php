<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the transaction_splits table — the leg store for Phase 13.1's
 * split-transactions-across-categories feature (Req 1).
 *
 * Each row is one "leg" of a split transaction: a slice of the parent
 * transaction's settled_amount_minor assigned to its own category_id. All
 * legs of a split share the parent's settled_currency (D-04b). The sum of
 * every leg's settled_amount_minor MUST equal the parent's settled_amount_minor
 * exactly — that invariant is enforced app-side inside the DB write
 * transaction by SaveTransactionSplit (D-04), never by a DB CHECK constraint,
 * mirroring the pair_transaction_id precedent
 * (2026_05_15_010002_add_pair_transaction_id_to_transactions.php).
 *
 * Deliberate deviation from every other `category_id` column in this
 * codebase (`transactions.category_id`, `category_budgets.category_id` are
 * both nullable): `category_id` here is REQUIRED — Req 5 depends on it. Do
 * not "fix" this to nullable by habit.
 *
 * `user_id` is a denormalized nullable copy of the parent's user_id (D-04c),
 * mirroring `pot_movements` and the project-wide "every domain table gets a
 * nullable user_id" convention (see UserIdColumnArchTest).
 *
 * `settled_amount_minor` is SIGNED BIGINT — never a float type
 * (NoFloatMoneyArchTest enforces this).
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('transaction_splits', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            // REQUIRED — no ->nullable(), deliberate deviation from
            // transactions.category_id (Req 5).
            $table->foreignId('category_id')->constrained('categories');
            // SIGNED — same sign as the parent (Req 7). BIGINT only, no float.
            $table->bigInteger('settled_amount_minor');
            // Always == parent.settled_currency (D-04b).
            $table->char('settled_currency', 3);
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['transaction_id']);
            $table->index(['user_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('transaction_splits');
    }
};
