<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the per-user `drift_alert_threshold_percent` column to `users`.
 *
 * Default `5` matches the global drift threshold default. The settings
 * surface lets the user dial this up or down once they get a feel for
 * the noise level on their own data; per-series overrides on
 * `recurring_series.drift_threshold_percent` win when set.
 *
 * Anchored next to `recurring_income_min_amount_minor` so the
 * `/settings` page renders the recurring-related per-user preferences
 * together. Mirrors the existing `add_recurring_settings_to_users`
 * precedent of keeping users-table migrations under
 * `Modules/Recurring/Database/Migrations/`.
 */
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
