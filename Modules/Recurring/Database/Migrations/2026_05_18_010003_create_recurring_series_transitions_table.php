<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the recurring_series_transitions append-only audit table —
 * one row per state transition produced by
 * `RecurringSeriesStateMachine`.
 *
 * Each transition row carries `from_state`, `to_state`, the actor
 * (`user` or `detector`), and the structured `transition_reason`
 * (e.g. `user_action`, `detector_cadence_flip`, `detector_promoted`,
 * `snooze_expired`). Optional `notes` text holds longer-form context
 * surfaced by the review surface. `transitioned_at` is the canonical
 * timestamp; created_at + updated_at fall through to Laravel
 * convention.
 *
 * The (recurring_series_id, transitioned_at) index supports the drill-
 * in audit query that walks the history of a single series in
 * chronological order.
 *
 * No DDL trigger on the transitions table — append-only behaviour is
 * a project-wide schema invariant rather than a per-table SQL guard.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('recurring_series_transitions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('recurring_series_id')->constrained('recurring_series')->cascadeOnDelete();
            $table->string('from_state', 24);
            $table->string('to_state', 24);
            $table->string('transition_reason', 64);
            $table->string('actor', 16);
            $table->timestamp('transitioned_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['recurring_series_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('recurring_series_transitions');
    }
};
