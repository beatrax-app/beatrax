<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Makes tax_deduction_categories.short_name nullable.
 *
 * The Plan 01 migration created short_name as NOT NULL, but the domain model
 * requires it to be optional (corpus entries and user categories may omit a
 * short name). This corrective migration brings the schema in line with the
 * PATTERNS.md specification.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('tax_deduction_categories', static function (Blueprint $table): void {
            $table->string('short_name', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->schema()->table('tax_deduction_categories', static function (Blueprint $table): void {
            $table->string('short_name', 32)->nullable(false)->change();
        });
    }
};
