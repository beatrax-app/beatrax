<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the anomaly_alert_transitions append-only audit table — one
 * row per state transition produced by `AnomalyAlertStateMachine`.
 *
 * Each row carries `from_state`, `to_state`, the actor (`user` or
 * `detector`), and the structured `transition_reason`. Optional `notes`
 * text holds longer-form context surfaced by the alerts page.
 * `transitioned_at` is the canonical timestamp; `created_at` +
 * `updated_at` fall through to Laravel convention.
 *
 * The (anomaly_alert_id, transitioned_at) index supports the drill-in
 * audit query that walks the history of a single alert in chronological
 * order.
 *
 * No DDL trigger on the transitions table — append-only behaviour is a
 * project-wide schema invariant rather than a per-table SQL guard.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('anomaly_alert_transitions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('anomaly_alert_id')->constrained('anomaly_alerts')->cascadeOnDelete();
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('transition_reason', 64);
            $table->string('actor', 16);
            $table->timestamp('transitioned_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['anomaly_alert_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('anomaly_alert_transitions');
    }
};
