<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the three per-user anomaly settings columns to `users` (D-08,
 * D-11, D-13).
 *
 *   - `anomaly_sensitivity_percent` (default 50): the single user-facing
 *     sensitivity dial mirroring drift's `drift_alert_threshold_percent`.
 *     A statistical, explainable threshold the user tunes once they get a
 *     feel for the noise level on their own data.
 *   - `anomaly_min_amount_minor` (default 1000 = €10.00): the global
 *     minimum-amount floor — anomalies under this value never fire across
 *     all three detectors, and the floor gates the large-AND-first-time
 *     rule (D-09/D-11).
 *   - `anomaly_backfilled_at` (nullable): first-activation guard. NULL
 *     until the full-history backfill (D-13) has run for the user;
 *     stamped once so the backfill is a one-shot.
 *
 * Anchored after `drift_alert_threshold_percent` so the /settings page
 * renders the alert-related per-user preferences together.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('anomaly_sensitivity_percent')
                ->default(50)
                ->after('drift_alert_threshold_percent');
            $table->bigInteger('anomaly_min_amount_minor')
                ->default(1000)
                ->after('anomaly_sensitivity_percent');
            $table->timestamp('anomaly_backfilled_at')
                ->nullable()
                ->after('anomaly_min_amount_minor');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn([
                'anomaly_sensitivity_percent',
                'anomaly_min_amount_minor',
                'anomaly_backfilled_at',
            ]);
        });
    }
};
