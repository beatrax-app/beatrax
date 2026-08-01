<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Saved what-if scenarios. Each scenario is a named container for a
 * list of mutations the user wants to model against the baseline
 * forecast. Strictly walled off from the transaction substrate —
 * no JOIN onto transactions / recurring_series / chain_links is
 * permitted (enforced by the noScenarioMutationsJoinedToTransactionQueries
 * arch test).
 *
 * `user_id` is non-nullable + cascade-on-delete: scenarios are
 * user-owned and deleting the user wipes their scenarios cleanly.
 * Non-null at the schema layer (matching the chain_resolution_runs +
 * forecast_runs precedent) so a future code path that forgets to set
 * user_id surfaces immediately at INSERT time rather than landing a
 * NULL row that escapes every `where('user_id', ...)` filter — and so
 * SQLite's NULL-distinct-in-UNIQUE behaviour cannot let two NULL-user
 * scenarios with the same name coexist.
 *
 * Indexes:
 *   - UNIQUE(user_id, name) — scenario names are unique per-user, so
 *     the rename action has a deterministic conflict surface.
 *   - INDEX(user_id, created_at) — scenario picker sorts by recency.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('forecast_scenarios', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_scenarios');
    }
};
