<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// An allocation ledger kept entirely apart from `transactions`: these rows are
// virtual moves of money the user already has, so they must never reach
// forecasts, cash-flow surfaces or categorisation. A transfer is two rows — a
// negative on the source pot, a positive on the target — so a balance is a SUM.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('pot_movements', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('pot_id')->constrained('pots')->cascadeOnDelete();
            // The sibling pot on a transfer row; null for fund and withdraw.
            $table->foreignId('counterpart_pot_id')->nullable()->constrained('pots')->nullOnDelete();
            // Signed: positive into the pot, negative out of it.
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
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
