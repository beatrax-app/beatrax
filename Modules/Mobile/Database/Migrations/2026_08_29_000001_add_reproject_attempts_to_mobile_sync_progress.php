<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('mobile_sync_progress', static function (Blueprint $table): void {
            // Counted before the pass runs, not after it returns. Memory
            // exhaustion is E_ERROR rather than a Throwable, so a re-projection
            // that dies takes the process with it and no catch ever runs; a
            // count already on disk is the only trace the next tick can read.
            $table->unsignedInteger('reproject_attempts')->default(0)->after('reprojected_at');
        });
    }

    public function down(): void
    {
        $this->schema()->table('mobile_sync_progress', static function (Blueprint $table): void {
            $table->dropColumn('reproject_attempts');
        });
    }
};
