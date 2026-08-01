<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the pending_enrichment_conflicts table — the side-channel
 * store for receipt-vs-CSV field disagreements that surface while the
 * per-user receipt_conflict_resolution setting is still `unset`.
 *
 * One row per (user_id, transaction_id, field_name) conflict — the
 * UNIQUE constraint makes the table re-conflict-idempotent so a
 * second receipt import does not duplicate the pending row. Once the
 * user chooses a policy on the conflict toast, the row is either
 * applied to the transaction (prefer_receipt) or archived
 * (prefer_first_write) and pruned from this table.
 *
 * `stored_value` and `incoming_value` are stored as nullable text
 * because the conflicting field may be merchant_name (short string)
 * or description (long string). The (user_id, created_at) index
 * supports the TTL sweep job that prunes resolved rows.
 */
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
