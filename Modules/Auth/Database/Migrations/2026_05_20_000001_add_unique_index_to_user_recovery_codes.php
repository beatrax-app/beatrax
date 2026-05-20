<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds a unique index on user_recovery_codes.code_hash.
 *
 * Recovery-code redemption looks a code up by its hash; the unique index
 * guarantees at most one row can match, so the redemption path never has
 * to disambiguate between two rows carrying the same hash. It also makes
 * a duplicate-code insert fail deterministically at the database layer.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('user_recovery_codes', static function (Blueprint $table): void {
            $table->unique('code_hash', 'user_recovery_codes_code_hash_unique');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_recovery_codes', static function (Blueprint $table): void {
            $table->dropUnique('user_recovery_codes_code_hash_unique');
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
