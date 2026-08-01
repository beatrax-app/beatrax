<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Plan 05 Rule 2 fix: `migration_staging_transactions` (Plan 01,
 * `2026_07_06_000002_create_migration_staging_tables.php`) was created with
 * no per-row category column at all — every OTHER staging table
 * (categories/accounts/payees/budget_assignments) carries the source
 * identity it needs, but a staged transaction had no column to carry
 * `MigrationTransactionDto::$categorySourceExternalId` (a plain transaction's
 * whole category) or a split leg's own `category_source_external_id`
 * through to the future `PromoteStagingToDomain` (Plan 06). Without this
 * column, Req 2/4 (category import onto transactions and split legs) would
 * be structurally impossible: `StagingWriter` would have nowhere to persist
 * the category a transaction or split leg resolves to.
 *
 * `StagingWriter` (this plan) flattens a split-parent `MigrationTransactionDto`
 * into one parent row (`is_split_parent = true`, this column NULL — category
 * lives on each leg, never on the parent, matching the DTO's own
 * `categorySourceExternalId === null` split-parent convention) plus one
 * additional row per leg (`is_split_parent = false`,
 * `parent_source_external_id` set to the parent's `source_external_id`, this
 * column set to the leg's own `category_source_external_id`).
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('migration_staging_transactions', static function (Blueprint $table): void {
            $table->string('category_source_external_id')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        $this->schema()->table('migration_staging_transactions', static function (Blueprint $table): void {
            $table->dropColumn('category_source_external_id');
        });
    }
};
