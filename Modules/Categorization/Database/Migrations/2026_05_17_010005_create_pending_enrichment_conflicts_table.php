<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Holds a receipt-vs-CSV disagreement until the user picks a policy. The
// UNIQUE constraint is the idempotency seam: re-importing the same receipt
// cannot duplicate a pending row. The (user_id, created_at) index serves the
// TTL sweep that prunes resolved rows.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('pending_enrichment_conflicts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('field_name', 64);
            $table->text('stored_value')->nullable();
            $table->text('incoming_value')->nullable();
            $table->string('incoming_source_format', 32);
            $table->foreignId('import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'transaction_id', 'field_name']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('pending_enrichment_conflicts');
    }
};
