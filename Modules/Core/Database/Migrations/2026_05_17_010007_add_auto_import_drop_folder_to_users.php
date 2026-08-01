<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `auto_import_drop_folder` boolean column to `users` — the
 * per-user toggle for the optional watched-folder secondary path
 * driving file-drop receipt ingestion.
 *
 * Default `false` so the wizard upload path remains the documented
 * primary entrypoint; the watched folder activates only when the
 * user explicitly opts in via /settings.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->boolean('auto_import_drop_folder')
                ->default(false)
                ->after('receipt_conflict_resolution');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('auto_import_drop_folder');
        });
    }
};
