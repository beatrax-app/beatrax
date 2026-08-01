<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Audit trail for ProjectForecastJob runs. Each row records the
 * (user, scenario, horizon) triple, the run's lifecycle timestamps,
 * and the terminal status. ForecastRunStateMachine is the sole writer
 * of the status column.
 *
 * `user_id` is NON-NULLABLE here — mirrors the chain_resolution_runs
 * shape (every run maps to exactly one user; the run id is never
 * shared across users) to eliminate NULL-distinct-in-UNIQUE bugs.
 *
 * `scenario_id` is nullable: NULL = baseline projection run; non-NULL
 * = scenario projection run.
 *
 * Status lifecycle: `pending` → `running` → `complete` / `failed`.
 * The ForecastRunStateMachine enforces the transition map; no
 * `failed_reason` column lives here — Horizon's failed-job log
 * captures the reason (Phase 5 precedent).
 *
 * Indexes:
 *   - (user_id, scenario_id, horizon_days, status) — the wire:poll.2s
 *     projection-status query selects on exactly this tuple.
 *   - (user_id, started_at) — recency listing.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('forecast_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('forecast_scenarios')->cascadeOnDelete();
            $table->unsignedSmallInteger('horizon_days');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->index(['user_id', 'scenario_id', 'horizon_days', 'status']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_runs');
    }
};
