<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('migration_staging_transactions', static function (Blueprint $table): void {
            // NULL on a split parent: the category lives on each leg.
            $table->string('category_source_external_id')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        $this->schema()->table('migration_staging_transactions', static function (Blueprint $table): void {
            $table->dropColumn('category_source_external_id');
        });
    }
};
