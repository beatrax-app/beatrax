<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the nullable `locale` column to `users` — the per-user language
 * override driving the translator locale and the `<html lang>` attribute.
 *
 * NULL means "auto": the language follows the browser's Accept-Language on
 * every request and falls back to English. A non-null `en` / `nl` is an
 * explicit choice the user made in Settings, which then wins over detection
 * on every device until they change it.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
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
