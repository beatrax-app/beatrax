<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the `dev_mode_audit` table — the persistent audit-log surface
 * the Dev Console writes to via the spatie/laravel-activitylog package.
 *
 * The table is the spatie-published `activity_log` schema renamed per
 * CONTEXT D-23 (`config('activitylog.table_name')` resolves to
 * `dev_mode_audit`). Renaming at the schema level keeps the column
 * shape spatie expects (so `Activity::query()` and `activity()->log()`
 * resolve the right row) while making the table's purpose explicit in
 * the database: the name reads as "Dev Console audit" rather than the
 * generic "activity log".
 *
 * Rows captured here cover every Dev Console action that crosses an
 * operational trust boundary:
 *
 *  - Artisan command runs (SAFE-tier and DESTRUCTIVE-tier), with the
 *    resolved name, args, exit code, stdout/stderr excerpts, and the
 *    causer user_id.
 *  - SELECT-only SQL queries executed through the Read-only SQL panel,
 *    with the verbatim query, rowcount, and duration.
 *  - Destructive queue actions (retry-failed, flush-failed, kill-batch)
 *    with the action name, context, and causer user_id.
 *
 * Concrete writers (`SpatieAuditWriter`) and the per-action call sites
 * land in Wave 4 plans (16-04 + 16-04b). This plan only stands up the
 * table so the binding shape is in place when those writers boot.
 *
 * Mirrors PATTERN G from 16-PATTERNS.md (anonymous-class migration with
 * an injected DatabaseManager) — the same shape system_alerts uses, so
 * the migration is reviewable as one of a pair.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('dev_mode_audit', static function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('dev_mode_audit');
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
