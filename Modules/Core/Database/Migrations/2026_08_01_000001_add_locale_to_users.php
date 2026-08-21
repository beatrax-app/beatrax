<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            // NULL is "auto": the language follows Accept-Language on every
            // request. A stored 'en'/'nl' is an explicit Settings choice and
            // outranks detection on every device until it changes.
            $table->string('locale', 8)
                ->nullable()
                ->after('theme');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
