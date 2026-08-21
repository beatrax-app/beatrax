<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Default 5 matches the global drift threshold; a per-series
// recurring_series.drift_threshold_percent override wins when set.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('drift_alert_threshold_percent')
                ->default(5)
                ->after('recurring_income_min_amount_minor');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('drift_alert_threshold_percent');
        });
    }
};
