<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// The same sweep, the same shape: every index on this table leads with
// `user_id`, and the sweep is global by design, so the hourly pass scanned the
// whole alert history to find the handful whose snooze had expired.
/**
 * @link ../../../../.docs/features/drift-alerts/snooze-lifecycle.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasTable('drift_alerts')) {
            return;
        }

        $this->db()->connection($this->getConnection())->statement(
            'CREATE INDEX IF NOT EXISTS drift_alerts_state_idx ON drift_alerts(state)'
        );
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())->statement(
            'DROP INDEX IF EXISTS drift_alerts_state_idx'
        );
    }
};
