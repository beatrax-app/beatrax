<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the anomaly_alerts table — one row per detected unusual charge
 * for a single transaction. Mirrors the drift_alerts shape but is keyed
 * per-transaction rather than per-recurring-series (D-01/D-16): anomalies
 * are one-off-charge events, not series-coupled.
 *
 * Money columns follow the project-wide BIGINT signed minor-units
 * convention. `baseline_amount_minor` / `latest_amount_minor` are
 * nullable because not every detector path carries an amount baseline
 * (e.g. a first-time-merchant flag has no prior per-merchant amount).
 * Currency is captured per-alert because the source charge may bill in a
 * non-EUR currency (Google Play in USD, ICS cross-currency settlements).
 *
 * `reasons` is a JSON list of the detector reasons the charge tripped
 * (large / first_time / duplicate). Per D-16 a charge is one alert,
 * multi-reason — never duplicate feed items.
 *
 * The `state` column is an enum-shaped string enforced via a BEFORE
 * INSERT / BEFORE UPDATE trigger pair targeting the `state` column
 * specifically. The single legal mutator is
 * `Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine`;
 * a BoundaryArchTest invariant (noOtherAnomalyAlertStateMutator) blocks
 * any other write path. Allowed states: open / acknowledged / snoozed /
 * dismissed.
 *
 * The UNIQUE constraint on `(transaction_id)` is the detector's
 * idempotency seam (D-16) — re-imports and sweeps re-running the
 * evaluator for the same charge cannot insert a duplicate alert.
 *
 * `dismissed_as` records the dismissal classification (e.g.
 * "not_unusual") that drives suppression-rule creation (D-17). It is a
 * companion column patched via the state machine's $extraColumns, never
 * via the state arg.
 *
 * Two read indexes support the projection layer's hot paths:
 *   - `(user_id, state)` — top-nav open-count badge query.
 *   - `(user_id, state, detected_at)` — anomaly page list ordered by
 *     detected_at DESC.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('anomaly_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('state', 16)->default('open');
            $table->enum('direction', ['expense', 'income']);
            $table->json('reasons');
            $table->string('dismissed_as', 16)->nullable();
            $table->bigInteger('baseline_amount_minor')->nullable();
            $table->bigInteger('latest_amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedTinyInteger('sensitivity_percent_used')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['transaction_id'], 'anomaly_alerts_uniq');
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'state', 'detected_at']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStates = "'open','acknowledged','snoozed','dismissed'";

        $connection->statement(sprintf(
            "CREATE TRIGGER anomaly_alerts_state_check_insert BEFORE INSERT ON anomaly_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid anomaly_alerts.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER anomaly_alerts_state_check_update BEFORE UPDATE OF state ON anomaly_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid anomaly_alerts.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS anomaly_alerts_state_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS anomaly_alerts_state_check_update');

        $this->schema()->dropIfExists('anomaly_alerts');
    }
};
