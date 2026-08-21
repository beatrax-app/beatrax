<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// A pivot, not a goal_id column on `transactions`: that table is hot and heavily
// synced, and one transaction may later fund several goals. It carries no amount
// of its own — the funded figure is read back through the joined transaction, so
// an edit or an FX restatement can never drift away from the attribution.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('goal_contributions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained('goals')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->timestamps();

            // The idempotency seam: a double-submit, or the same op replayed
            // from a peer, inserts once.
            $table->unique(['goal_id', 'transaction_id']);
            $table->index(['user_id', 'goal_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('goal_contributions');
    }
};
