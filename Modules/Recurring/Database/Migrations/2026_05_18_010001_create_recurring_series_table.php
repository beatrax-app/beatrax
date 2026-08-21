<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// UNIQUE(user_id, direction, cluster_key, latest_currency) is the detector's
// idempotency seam: re-running the sweep cannot duplicate a cluster. The state
// trigger pair keeps RecurringSeriesStateMachine the only legal mutator, and
// latest_fx_rate_used stays a string so the rate survives without a lossy cast.
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
