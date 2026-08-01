<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('import_runs', static function (Blueprint $table): void {
            $table->unsignedInteger('enriched_count')->default(0)->after('duplicate_count');
        });
    }

    public function down(): void
    {
        $this->schema()->table('import_runs', static function (Blueprint $table): void {
            $table->dropColumn('enriched_count');
        });
    }
};
