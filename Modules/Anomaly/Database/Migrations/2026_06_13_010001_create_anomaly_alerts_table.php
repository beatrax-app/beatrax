<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

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
            // Nullable because the duplicate and first-time paths carry no
            // per-merchant amount baseline; currency is per-alert because the
            // charge may settle in something other than the base currency.
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
