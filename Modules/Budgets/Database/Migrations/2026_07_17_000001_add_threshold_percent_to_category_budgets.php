<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Nullable with no DB default, so the default lives in one place in PHP.
// Superseded by the 000002 drop migration.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('threshold_percent')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->dropColumn('threshold_percent');
        });
    }
};
