<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the recurring_series_occurrences table — the per-series link
 * to the underlying transaction rows that contributed to the cluster.
 *
 * Each row captures one observation (one transaction belonging to one
 * series) along with the observed amount + currency at that point in
 * time. The detector's sweep produces these rows so the drill-in chart
 * can render the amount-over-time trend without re-deriving it from the
 * transaction table on every render.
 *
 * `user_id` is carried directly on the row (rather than relying on a
 * JOIN through `recurring_series_id`) so cross-user 404 reads stay a
 * single-table query. `recurring_series_id` and `transaction_id` both
 * cascade-delete: a removed series or transaction implies the link
 * row has no remaining meaning.
 *
 * UNIQUE(`recurring_series_id`, `transaction_id`) is the idempotency
 * seam: re-running the detector sweep cannot create a duplicate
 * occurrence row for the same (series, transaction) pair.
 *
 * The (`recurring_series_id`, `observed_at`) index supports the drill-
 * in chart query that walks the occurrences in chronological order.
 */
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
