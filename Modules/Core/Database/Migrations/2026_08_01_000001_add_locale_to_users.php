<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds the nullable `locale` column to `users` — the per-user language
 * override driving the translator locale and the `<html lang>` attribute.
 *
 * NULL means "auto": the language follows the browser's Accept-Language on
 * every request and falls back to English. A non-null `en` / `nl` is an
 * explicit choice the user made in Settings, which then wins over detection
 * on every device until they change it.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

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

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
