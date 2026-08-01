<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the pot_movements table — an append-only allocation ledger for pot
 * operations (D-06).
 *
 * D-06: Movements are entirely separate from the real `transactions` table.
 * Pot movements must NEVER appear in forecasts, cash-flow surfaces, or
 * categorization — they represent virtual allocations, not real money flows.
 *
 * D-07: Three operation kinds:
 *   fund          — unallocated → pot (positive amount_minor)
 *   withdraw      — pot → unallocated (negative amount_minor)
 *   transfer_in   — sibling pot → this pot (positive; paired with transfer_out)
 *   transfer_out  — this pot → sibling pot (negative; paired with transfer_in)
 *
 * Transfers are recorded as two rows in a DB transaction (Pattern 2): one
 * `transfer_out` row on the source pot and one `transfer_in` row on the target.
 * Each pot's balance = SUM(amount_minor) on its own pot_id with no special-casing.
 *
 * `amount_minor` is SIGNED BIGINT: positive = into pot, negative = out of pot.
 * Never use a float type for money (NoFloatMoneyArchTest enforces this).
 *
 * `counterpart_pot_id` is set for transfer rows (identifies the sibling pot);
 * null for fund/withdraw.
 *
 * `user_id` is NULLABLE per project convention; cascadeOnDelete mirrors pots.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('pot_movements', static function (Blueprint $table): void {
            $table->id();
            // NULLABLE per project multi-user convention.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('pot_id')->constrained('pots')->cascadeOnDelete();
            // For transfer kind: the sibling pot being debited/credited.
            $table->foreignId('counterpart_pot_id')->nullable()->constrained('pots')->nullOnDelete();
            // SIGNED: + = into pot, − = out of pot. BIGINT only — NO float.
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            // kind: 'fund' | 'withdraw' | 'transfer_in' | 'transfer_out'
            $table->string('kind', 32);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['pot_id']);
            $table->index(['user_id', 'pot_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('pot_movements');
    }
};
