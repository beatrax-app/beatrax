<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Anomaly\Internal\Support\AnomalySensitivity;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/anomaly/detector-maths.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('anomaly_sensitivity_percent')
                ->default(AnomalySensitivity::DEFAULT_PERCENT)
                ->after('drift_alert_threshold_percent');
            $table->bigInteger('anomaly_min_amount_minor')
                ->default(AnomalySensitivity::DEFAULT_MIN_AMOUNT_MINOR)
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
