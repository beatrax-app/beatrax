<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Deliberately no `balance`, `allocated` or `unallocated` column: they are
// derived at read time from real_balance − sum_of_pot_balances, and a stored
// copy could drift away from the real account balance.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('pots', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            // At most one of the two links is set; the XOR lives in PotWriter,
            // not in the schema.
            $table->foreignId('goal_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            // The account's native currency, frozen at creation, so reconciling
            // pots against the account stays exact integer maths with no FX step.
            $table->string('currency', 3);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['user_id', 'account_id', 'status']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('pots');
    }
};
