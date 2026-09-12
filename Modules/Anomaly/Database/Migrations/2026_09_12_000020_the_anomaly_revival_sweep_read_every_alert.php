<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// The revival sweep is deliberately unscoped by user, so neither index on this
// table leads with a column it filters on and the hourly pass read every alert
// the reader has ever been shown to find the few still snoozed.
/**
 * @link ../../../../.docs/architecture/reads-bounded-by-the-user.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasTable('anomaly_alerts')) {
            return;
        }

        $this->db()->connection($this->getConnection())->statement(
            'CREATE INDEX IF NOT EXISTS anomaly_alerts_state_idx ON anomaly_alerts(state)'
        );
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())->statement(
            'DROP INDEX IF EXISTS anomaly_alerts_state_idx'
        );
    }
};
