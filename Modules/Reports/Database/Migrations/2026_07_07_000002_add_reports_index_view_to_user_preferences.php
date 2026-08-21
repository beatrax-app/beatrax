<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Additive-only: if user_preferences is missing, the ALTER fails loud rather
// than silently re-creating a foundation table. The default materialises on
// existing rows at the DB boundary, so no backfill pass is needed.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->string('reports_index_view', 16)->default('cards');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->dropColumn('reports_index_view');
        });
    }
};
