<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// recurring_income_min_amount_minor defaults to 200000 — €2000.00 in signed
// BIGINT minor units — and 0 disables the threshold entirely.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('recurring_detection_window_months')
                ->default(18)
                ->after('auto_import_drop_folder');
            $table->bigInteger('recurring_income_min_amount_minor')
                ->default(200000)
                ->after('recurring_detection_window_months');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn(['recurring_detection_window_months', 'recurring_income_min_amount_minor']);
        });
    }
};
