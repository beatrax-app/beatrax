<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the goals table — one savings goal per user, with an optional link
 * to a specific account for contribution tracking.
 *
 * Money follows the project-wide signed BIGINT minor-units convention:
 * `target_minor` is always positive (a target amount, not a signed flow).
 * `target_currency` captures the base currency at creation time (D-05) so the
 * goal denomination is immutable even if the user later changes their default.
 *
 * `user_id` is NULLABLE per the project multi-user-ready convention (accounts,
 * transactions, categories all use nullable user_id). Budgets uses NOT NULL for
 * its unique-upsert constraint, but Goals has no such constraint, so the
 * convention is followed here.
 *
 * `account_id` is NULLABLE — an "unlinked" goal shows 0/target with no
 * projection (D-03). The `nullOnDelete` means deleting an account orphans the
 * goal (becomes unlinked) rather than cascade-deleting the goal.
 *
 * `status` lifecycle: active (default) → completed | archived.
 * The composite index on (user_id, status) supports the common query pattern
 * of loading a user's active goals.
 *
 * `NoFloatMoneyArchTest` scans migrations for float money columns — never
 * use a float column type for money.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('goals', static function (Blueprint $table): void {
            $table->id();
            // NULLABLE per project multi-user convention (A3 / Pitfall 6).
            // Goals has no unique-upsert constraint so NOT NULL is unnecessary.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Optional account link (D-03): nullOnDelete so deleting an account
            // orphans the goal (unlinked) rather than cascade-deleting it.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('name');
            // Signed BIGINT minor — NO float. Always positive in practice (a
            // target amount, not a signed flow); positivity is enforced in
            // GoalWriter::parseAmount, the sole write path. A DB-level CHECK is
            // intentionally omitted (overkill for SQLite-first) — a direct insert
            // or seeder bypassing the writer could store a non-positive value
            // (IN-02), so any future alternate write path must re-assert it.
            $table->bigInteger('target_minor');
            $table->string('target_currency', 3)->default('EUR');   // base currency at creation (D-05)
            $table->date('start_date');                             // contributions count from here (D-01)
            $table->date('target_date');
            $table->string('status', 16)->default('active');        // active | completed | archived
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('goals');
    }
};
