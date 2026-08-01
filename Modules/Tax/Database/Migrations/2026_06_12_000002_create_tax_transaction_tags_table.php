<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the tax_transaction_tags table.
 *
 * One row per tagged transaction — the join between a transaction and a
 * deduction category. Any transaction type (expense or income) is taggable.
 *
 * `user_id` is NULLABLE per the project multi-user-ready convention.
 * The unique(user_id, transaction_id) constraint enforces one tag per
 * transaction per user — re-tagging is an updateOrInsert, not a duplicate.
 *
 * `deduction_category_id` is nullable (nullOnDelete) so deleting a
 * category orphans the tag row rather than cascade-deleting it; this
 * preserves the audit trail for tax year exports even if the category
 * is removed.
 *
 * `tax_year_override` allows the user to assign a tag to a specific tax
 * year different from the transaction's booked_at year. Range-checked in
 * TagTransaction action (now ± 10 years).
 *
 * `note` carries a free-text memo relevant to the tax deduction (e.g.
 * "50% business use", "receipt #INV-2026-001").
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('tax_transaction_tags', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('deduction_category_id')
                ->nullable()
                ->constrained('tax_deduction_categories')
                ->nullOnDelete();
            $table->unsignedSmallInteger('tax_year_override')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'transaction_id']);
            $table->index(['user_id', 'deduction_category_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('tax_transaction_tags');
    }
};
