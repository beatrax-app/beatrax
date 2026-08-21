<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            // Only update.available consults this list. A stale or critical
            // update re-banners regardless: those threat models deliberately
            // override the user's earlier dismissal.
            $table->json('skipped_update_versions')->default('[]');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->dropColumn('skipped_update_versions');
        });
    }
};
