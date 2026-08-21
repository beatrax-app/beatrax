<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('tax_transaction_tags', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            // nullOnDelete, not cascade: deleting a category must orphan the tag
            // rather than erase it, so past tax-year exports stay reproducible.
            $table->foreignId('deduction_category_id')
                ->nullable()
                ->constrained('tax_deduction_categories')
                ->nullOnDelete();
            // Overrides the transaction's booked_at year; TagTransaction bounds it
            // to +/- 10 years.
            $table->unsignedSmallInteger('tax_year_override')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // One tag per transaction: re-tagging is an updateOrInsert.
            $table->unique(['user_id', 'transaction_id']);
            $table->index(['user_id', 'deduction_category_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('tax_transaction_tags');
    }
};
