<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `theme` column to `users` — the per-user appearance
 * preference driving the dark-mode class on `<html>`.
 *
 * One of `light` / `dark` / `system`. The default `system` makes dark
 * mode follow the operating system out of the box; the user can pin
 * the app to `light` or `dark` from the Settings Appearance section.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('theme', 16)
                ->default('system')
                ->after('auto_import_drop_folder');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
};
