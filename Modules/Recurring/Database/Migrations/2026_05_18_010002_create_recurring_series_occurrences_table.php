<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// user_id is carried on the row rather than reached through recurring_series_id,
// so a cross-user 404 read stays a single-table query. UNIQUE(recurring_series_id,
// transaction_id) is the idempotency seam: a re-run sweep cannot duplicate an
// occurrence for the same (series, transaction) pair.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('recurring_series_occurrences', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('recurring_series_id')->constrained('recurring_series')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->date('observed_at');
            $table->bigInteger('observed_amount_minor');
            $table->string('observed_currency', 3);
            $table->timestamps();

            $table->unique(['recurring_series_id', 'transaction_id'], 'rec_occ_uniq');
            $table->index(['recurring_series_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('recurring_series_occurrences');
    }
};
