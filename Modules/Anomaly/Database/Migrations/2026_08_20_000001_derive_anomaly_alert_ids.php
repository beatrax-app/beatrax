<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Ledger\Public\Enums\Direction;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $schema = $this->schema();

        $derived = [];
        foreach ($connection->table('anomaly_alerts')->select('id', 'user_id', 'transaction_id')->orderBy('id')->cursor() as $row) {
            $derived[(int) $row->id] = DerivedRowId::for('anomaly_alerts', [
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'transaction_id' => (int) $row->transaction_id,
            ]);
        }

        // Dropping AUTOINCREMENT rebuilds the table, and a SQLite table copy
        // carries neither the inline CHECK on `direction` (re-declared here)
        // nor the `state` triggers (recreated at the end).
        $schema->table('anomaly_alerts', static function (Blueprint $table): void {
            $table->bigInteger('id')->change();
            $table->enum('direction', array_column(Direction::cases(), 'value'))->change();
        });

        $schema->disableForeignKeyConstraints();

        try {
            foreach ($derived as $old => $new) {
                $connection->table('anomaly_alerts')->where('id', $old)->update(['id' => $new]);
                $connection->table('anomaly_alert_transitions')->where('anomaly_alert_id', $old)->update(['anomaly_alert_id' => $new]);
                $connection->table('anomaly_suppression_rules')->where('source_anomaly_alert_id', $old)->update(['source_anomaly_alert_id' => $new]);
            }
        } finally {
            $schema->enableForeignKeyConstraints();
        }

        $allowedStates = implode(',', array_map(
            static fn (string $value): string => "'".$value."'",
            array_column(AnomalyAlertState::cases(), 'value'),
        ));

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
        // Deliberately empty: the autoincrement values the derived ids replaced
        // were per-device and are not recoverable.
    }
};
