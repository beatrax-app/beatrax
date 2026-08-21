<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            // Nullable: a user has no tax country until they pick one in settings,
            // and the corpus loader filters its defaults on it.
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
