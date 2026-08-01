<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the pots table — one virtual savings pot per user, linked to a real
 * account. Each pot is denominated in the account's native currency (D-05).
 *
 * D-01: No `unallocated`, `allocated`, or `balance` columns — those values are
 * derived at read time (real_balance − sum_of_pot_balances). Storing them would
 * risk divergence from the real account balance.
 *
 * D-04: No savings-kind restriction on `account_id`. Any account can own a pot
 * (same as Phase 2 D-04 for goals). The FK cascades on delete so pots are
 * removed when their account is deleted.
 *
 * D-05: `currency` captures the account's native currency at pot creation time
 * so reconciliation is exact integer math with zero FX drift.
 *
 * `user_id` is NULLABLE per the project multi-user-ready convention (accounts,
 * transactions, goals all use nullable user_id).
 *
 * Optional links `goal_id` and `category_id` (at most one — XOR enforced in
 * PotWriter, not at DB level for SQLite-first simplicity): nullOnDelete so a
 * deleted goal/category orphans the link rather than cascade-deleting the pot.
 *
 * `status` lifecycle: active (default) → archived.
 *
 * `NoFloatMoneyArchTest` scans migrations for float money columns — never
 * use a float column type for money.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('pots', static function (Blueprint $table): void {
            $table->id();
            // NULLABLE per project multi-user convention.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // D-04: no savings-kind restriction — any account qualifies.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            // XOR link: goal_id XOR category_id — enforced in PotWriter, not DB level.
            $table->foreignId('goal_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('currency', 3);     // account's native currency (D-05)
            $table->string('status', 16)->default('active');  // active | archived
            $table->timestamps();

            $table->index(['user_id', 'account_id', 'status']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('pots');
    }
};
