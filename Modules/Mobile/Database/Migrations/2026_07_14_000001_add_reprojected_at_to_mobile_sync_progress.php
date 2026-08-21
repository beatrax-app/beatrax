<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('mobile_sync_progress', static function (Blueprint $table): void {
            // Stamps the one full-history re-projection each cursor gets, the
            // moment the keyring first becomes non-empty. Without the stamp
            // every wire:poll tick would rebuild the whole op log again.
            $table->text('reprojected_at')->nullable()->after('phase');
        });
    }

    public function down(): void
    {
        $this->schema()->table('mobile_sync_progress', static function (Blueprint $table): void {
            $table->dropColumn('reprojected_at');
        });
    }
};
