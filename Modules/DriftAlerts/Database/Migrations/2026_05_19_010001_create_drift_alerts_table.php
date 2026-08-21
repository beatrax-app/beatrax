<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// UNIQUE(recurring_series_id, latest_occurrence_id) is the detector's idempotency
// seam: re-evaluating the same occurrence cannot open a second alert.
// threshold_percent_used/threshold_source snapshot the effective threshold at
// detection, so a later override change never rewrites an existing alert.
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

        // Enforced in SQL as well as in DriftAlertStateMachine, so an out-of-band
        // write cannot store a state outside the enum.
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
