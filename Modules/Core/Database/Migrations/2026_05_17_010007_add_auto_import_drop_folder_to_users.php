<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds the `auto_import_drop_folder` boolean column to `users` — the
 * per-user toggle for the optional watched-folder secondary path
 * driving file-drop receipt ingestion.
 *
 * Default `false` so the wizard upload path remains the documented
 * primary entrypoint; the watched folder activates only when the
 * user explicitly opts in via /settings.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

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
