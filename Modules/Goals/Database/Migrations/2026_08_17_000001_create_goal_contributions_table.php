<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the goal_contributions pivot — the explicit record that a
 * transaction funds a savings goal.
 *
 * A pivot rather than a `goals.id` column on `transactions`: `transactions`
 * is the hot, heavily-synced table and stays untouched, and one transaction
 * can fund more than one goal later without another schema change.
 *
 * The table is append-only and carries no amount of its own — the funded
 * figure is always read back through the joined transaction, so an edited
 * or FX-restated amount can never drift from its attribution.
 *
 * `unique(goal_id, transaction_id)` makes an attribution idempotent: a
 * double-submit, or the same op replayed from a peer, inserts once.
 *
 * `user_id` is NULLABLE per the project multi-user convention;
 * cascadeOnDelete mirrors goals.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('goal_contributions', static function (Blueprint $table): void {
            $table->id();
            // NULLABLE per project multi-user convention.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained('goals')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['goal_id', 'transaction_id']);
            $table->index(['user_id', 'goal_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('goal_contributions');
    }
};
