<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('forecast_scenario_mutations', static function (Blueprint $table): void {
            $table->id();
            // Non-nullable: a NULL user_id would silently escape per-user filters.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('forecast_scenario_id')->constrained('forecast_scenarios')->cascadeOnDelete();
            $table->string('kind', 40);
            // Deliberately un-FK'd: `recurring_series` belongs to another module.
            // NULL for add_one_off / add_recurring, which target no series.
            $table->unsignedBigInteger('target_series_id')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['user_id', 'forecast_scenario_id']);
            $table->index('kind');
        });

        // The typed ORM cast only guards the Eloquent boundary; a raw INSERT or a
        // manual SQL fix would otherwise land an unknown kind. Mirrors the
        // recurring_series.state trigger pair.
        $conn = $this->db()->connection($this->getConnection());
        $conn->statement(<<<'SQL'
            CREATE TRIGGER forecast_scenario_mutations_kind_insert_check
            BEFORE INSERT ON forecast_scenario_mutations
            FOR EACH ROW
            WHEN NEW.kind NOT IN ('cancel_series', 'add_one_off', 'add_recurring', 'change_series_amount', 'shift_series_date')
            BEGIN
                SELECT RAISE(ABORT, 'forecast_scenario_mutations.kind must be one of: cancel_series, add_one_off, add_recurring, change_series_amount, shift_series_date');
            END
        SQL);

        $conn->statement(<<<'SQL'
            CREATE TRIGGER forecast_scenario_mutations_kind_update_check
            BEFORE UPDATE OF kind ON forecast_scenario_mutations
            FOR EACH ROW
            WHEN NEW.kind NOT IN ('cancel_series', 'add_one_off', 'add_recurring', 'change_series_amount', 'shift_series_date')
            BEGIN
                SELECT RAISE(ABORT, 'forecast_scenario_mutations.kind must be one of: cancel_series, add_one_off, add_recurring, change_series_amount, shift_series_date');
            END
        SQL);
    }

    public function down(): void
    {
        $conn = $this->db()->connection($this->getConnection());
        $conn->statement('DROP TRIGGER IF EXISTS forecast_scenario_mutations_kind_update_check');
        $conn->statement('DROP TRIGGER IF EXISTS forecast_scenario_mutations_kind_insert_check');
        $this->schema()->dropIfExists('forecast_scenario_mutations');
    }
};
