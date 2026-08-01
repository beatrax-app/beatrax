<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds tax_country_code (ISO 3166-1 alpha-2, e.g. "NL") to the users table.
 *
 * Nullable — existing users have no tax country configured until they set it
 * in the Tax settings. The Tax corpus loader (Plan 04) uses this column to
 * filter the default deduction category set shown on first use.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('tax_country_code', 2)->nullable()->after('period_start_day');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('tax_country_code');
        });
    }
};
