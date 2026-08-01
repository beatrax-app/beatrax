<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the recurring_series table — the unified detector output for
 * both expense and income recurring patterns.
 *
 * One row models one detected pattern for one user. The `direction`
 * enum keeps the expense and income detector code paths sharing a
 * single table so downstream queries, projections, and the review
 * surface read against one shape rather than two parallel tables.
 *
 * The `state` column is an enum-shaped string enforced via a BEFORE
 * INSERT / BEFORE UPDATE trigger pair targeting the `state` column
 * specifically. The single legal mutator is
 * `Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine`;
 * a BoundaryArchTest invariant blocks any other write path.
 * Allowed states: pending / approved / rejected / snoozed /
 * cadence_changed.
 *
 * The UNIQUE constraint on `(user_id, direction, cluster_key,
 * latest_currency)` is the detector's idempotency seam — re-running the
 * sweep job for the same user cannot insert a duplicate row for the
 * same logical cluster.
 *
 * Two read indexes support the hot paths the projection layer uses:
 *   - `(user_id, state)` — top-nav pending-count badge query.
 *   - `(user_id, state, next_expected_at)` — "This month only" toggle
 *     on the fixed-payments view; the index lets the optimiser walk
 *     the date filter without a table scan.
 *
 * Money columns follow the project-wide BIGINT signed minor-units
 * convention; `latest_fx_rate_used` is captured as a free-form string
 * so the original EUR/USD rate audit trail survives without a lossy
 * decimal cast.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('recurring_series', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('direction', ['expense', 'income']);
            $table->string('detected_name');
            $table->string('display_name_override')->nullable();
            $table->string('state', 24)->default('pending');
            $table->string('cadence', 24)->default('irregular');
            $table->bigInteger('latest_amount_minor');
            $table->string('latest_currency', 3);
            $table->string('latest_fx_rate_used')->nullable();
            $table->bigInteger('monthly_equivalent_minor')->nullable();
            $table->unsignedTinyInteger('variance_tolerance_percent')->default(25);
            $table->foreignId('latest_funding_chain_link_id')
                ->nullable()
                ->constrained('chain_links')
                ->nullOnDelete();
            $table->timestamp('snoozed_until')->nullable();
            $table->date('next_expected_at')->nullable();
            $table->boolean('next_expected_confidence_low')->default(false);
            $table->string('cluster_key');
            $table->timestamps();

            $table->unique(['user_id', 'direction', 'cluster_key', 'latest_currency'], 'rec_series_uniq');
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'state', 'next_expected_at']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStates = "'pending','approved','rejected','snoozed','cadence_changed'";

        $connection->statement(sprintf(
            "CREATE TRIGGER recurring_series_state_check_insert BEFORE INSERT ON recurring_series FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER recurring_series_state_check_update BEFORE UPDATE OF state ON recurring_series FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS recurring_series_state_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS recurring_series_state_check_update');

        $this->schema()->dropIfExists('recurring_series');
    }
};
