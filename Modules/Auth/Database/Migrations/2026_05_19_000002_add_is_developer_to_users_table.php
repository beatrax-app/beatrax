<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds the `is_developer` flag to the `users` table.
 *
 * The flag (default false) marks a user as eligible for the in-app
 * developer console; the column lives on `users` so the developer-mode
 * middleware can read it without a join.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->boolean('is_developer')->default(false)->after('username');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('is_developer');
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
