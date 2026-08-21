<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// A leg's settled_amount_minor is a slice of the parent's, and the legs must
// sum to it exactly. SaveTransactionSplit enforces that inside the write
// transaction rather than a DB CHECK, mirroring pair_transaction_id.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('transaction_splits', static function (Blueprint $table): void {
            $table->id();
            // Denormalised copy of the parent's user_id.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            // Required, unlike transactions.category_id — a deliberate deviation.
            $table->foreignId('category_id')->constrained('categories');
            // Signed, matching the parent's sign.
            $table->bigInteger('settled_amount_minor');
            // Always the parent's settled_currency.
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
