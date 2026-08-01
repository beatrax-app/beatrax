<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the drift_alerts table — one row per detected drift event for
 * a single approved / cadence-changed recurring series.
 *
 * Money columns follow the project-wide BIGINT signed minor-units
 * convention. `delta_minor` and `annualized_impact_minor` are signed
 * (an expense drift downward is a negative delta on a negative
 * baseline, so the sign reads as a real cash-flow direction). Currency
 * is captured per-alert because the source series may bill in a
 * non-EUR currency (Google Play in USD, ICS cross-currency
 * settlements).
 *
 * The `state` column is an enum-shaped string enforced via a BEFORE
 * INSERT / BEFORE UPDATE trigger pair targeting the `state` column
 * specifically. The single legal mutator is
 * `Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine`;
 * a BoundaryArchTest invariant blocks any other write path. Allowed
 * states: open / acknowledged / snoozed / dismissed_cancelled.
 *
 * The UNIQUE constraint on `(recurring_series_id, latest_occurrence_id)`
 * is the detector's idempotency seam — re-running the evaluator for
 * the same (series, occurrence) pair cannot insert a duplicate alert.
 *
 * Three read indexes support the projection layer's hot paths:
 *   - `(user_id, state)` — top-nav open-count badge query.
 *   - `(user_id, state, detected_at)` — drift page list ordered by
 *     detected_at DESC.
 *   - `(user_id, recurring_series_id, state)` — grouped-by-series
 *     drill-in query.
 *
 * `threshold_percent_used` + `threshold_source` capture the effective
 * threshold at the moment the alert was opened, so subsequent changes
 * to the per-series override or per-user default never rewrite history.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('drift_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('recurring_series_id')->constrained('recurring_series')->cascadeOnDelete();
            $table->string('state', 24)->default('open');
            $table->enum('direction', ['expense', 'income']);
            $table->bigInteger('baseline_amount_minor');
            $table->bigInteger('latest_amount_minor');
            $table->string('currency', 3);
            $table->bigInteger('delta_minor');
            $table->bigInteger('annualized_impact_minor');
            $table->unsignedTinyInteger('threshold_percent_used');
            $table->string('threshold_source', 24);
            $table->foreignId('latest_occurrence_id')
                ->constrained('recurring_series_occurrences')
                ->cascadeOnDelete();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['recurring_series_id', 'latest_occurrence_id'], 'drift_alerts_uniq');
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'state', 'detected_at']);
            $table->index(['user_id', 'recurring_series_id', 'state']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStates = "'open','acknowledged','snoozed','dismissed_cancelled'";

        $connection->statement(sprintf(
            "CREATE TRIGGER drift_alerts_state_check_insert BEFORE INSERT ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER drift_alerts_state_check_update BEFORE UPDATE OF state ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS drift_alerts_state_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS drift_alerts_state_check_update');

        $this->schema()->dropIfExists('drift_alerts');
    }
};
