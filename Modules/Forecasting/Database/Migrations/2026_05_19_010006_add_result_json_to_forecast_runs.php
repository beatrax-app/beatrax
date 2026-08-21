<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('forecast_runs', static function (Blueprint $table): void {
            // Nullable: a pending, running or failed run carries no payload, so
            // there is no half-written body to distinguish from a complete one.
            $table->text('result_json')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        $this->schema()->table('forecast_runs', static function (Blueprint $table): void {
            $table->dropColumn('result_json');
        });
    }
};
