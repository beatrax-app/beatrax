<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the optional per-series `drift_threshold_percent` column to
 * `recurring_series`.
 *
 * Each series may opt out of the user-global drift threshold by
 * setting an override here. Leaving the column null means "use the
 * user's `users.drift_alert_threshold_percent` value (or the global
 * default when the user has not customised it)". Sits adjacent to
 * `variance_tolerance_percent` so both editor surfaces render them
 * together.
 *
 * This migration lives in `Modules/Recurring/Database/Migrations/`
 * because the column targets the `recurring_series` table.
 */
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
