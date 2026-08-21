<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Null means "use users.drift_alert_threshold_percent, or the global default
// when the user has not customised it" — this column is the per-series opt-out.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('drift_threshold_percent')
                ->nullable()
                ->after('variance_tolerance_percent');
        });
    }

    public function down(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->dropColumn('drift_threshold_percent');
        });
    }
};
