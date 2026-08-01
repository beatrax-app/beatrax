<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the `dev_mode_audit` table — the persistent audit-log
 * surface the Dev Console writes to via the
 * spatie/laravel-activitylog package.
 *
 * The table is the spatie-published `activity_log` schema renamed
 * to dev_mode_audit. The DevModeActivity model overrides $table to
 * point at the renamed table; the column shape is what spatie
 * expects so Activity::query() and the ActivityLogger pipeline
 * resolve rows normally. The rename makes the table's purpose
 * explicit in the database — "Dev Console audit" rather than the
 * generic "activity log".
 *
 * Rows captured here cover every Dev Console action that crosses
 * an operational trust boundary:
 *
 *  - Artisan command runs (SAFE-tier and DESTRUCTIVE-tier), with
 *    the resolved name, args, exit code, stdout/stderr excerpts,
 *    and the causer user_id.
 *  - SELECT-only SQL queries executed through the read-only SQL
 *    panel, with the verbatim query, rowcount, and duration.
 *  - Destructive queue actions (retry-failed, flush-failed,
 *    kill-batch) with the action name, context, and causer
 *    user_id.
 *
 * Uses the anonymous-class-migration shape with an injected
 * DatabaseManager — the same pattern other Core migrations
 * (system_alerts, etc.) follow.
 */
return new class extends ModuleMigration
{
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
};
