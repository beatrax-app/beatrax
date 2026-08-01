<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Plan 06 Rule 2 fix: `MigrationBatch::$goals` (a `Collection<MigrationGoalDto>`,
 * populated by `ActualParser` from a category's flat `goal_def` — Req 8) had
 * NO staging table to land in at all. `StagingWriter::write()` (Plan 05)
 * persists every OTHER bounded collection (categories/accounts/payees/budget
 * assignments/already-known-unmapped items) but never touched `$batch->goals`
 * — meaning a parsed goal was silently discarded between parse and confirm,
 * making Req 8's "Actual goal -> a savings goal" promotion structurally
 * impossible for `PromoteStagingToDomain` (this plan) to implement. Mirrors
 * the identical Plan 05 precedent that added
 * `migration_staging_transactions.category_source_external_id` for the same
 * reason.
 *
 * One row per `MigrationGoalDto`. `category_source_external_id` is the goal's
 * owning category's own staging identity (Actual's `goal_def` lives ON the
 * category, so a goal's natural key IS its category's — no separate id is
 * ever needed). `target_date` is nullable: `ActualGoalDefInterpreter` returns
 * a flat goal with no `targetDate` key at all when the source `goal_def` omits
 * one (the golden fixture's own "Groceries" goal is exactly this case) —
 * `PromoteStagingToDomain` reports a dateless goal as an unmapped "extra" item
 * rather than inventing a target date, since `goals.target_date` is NOT NULL
 * on the real domain table and `GoalWriter::save()` requires a non-null
 * string.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('migration_staging_goals', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            $table->string('category_source_external_id');
            $table->string('name');
            $table->bigInteger('target_minor');
            $table->char('target_currency', 3);
            $table->date('target_date')->nullable();

            $table->index(['migration_run_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_staging_goals');
    }

    /**
     * Mirrors the identical scope-column pair every other
     * `migration_staging_*` table carries (2026_07_06_000002).
     */
    private function scopeColumns(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
    }
};
