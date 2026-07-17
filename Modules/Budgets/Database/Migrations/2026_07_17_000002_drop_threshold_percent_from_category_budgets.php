<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Supersedes 2026_07_17_000001: drops `category_budgets.threshold_percent`.
 *
 * Mid-plan redirect (18-02, Option B): `category_budgets` is the write-dead,
 * pre-envelope-cutover table (D-13 hard cutover — no live UI creates a
 * `category_budgets` row anymore, and `BudgetProgressQuery::forCurrentPeriod()`
 * returns `[]` for every post-cutover user). A per-budget notify threshold
 * placed there would never fire for a real user. Req 6's D-20 threshold moved
 * to the LIVE envelope model (`envelope_settings.threshold_percent`, added by
 * the sibling 2026_07_17_000003 migration).
 *
 * Rather than rewrite the already-committed 000001 add migration out of
 * history, this forward-only drop migration keeps the schema honest on every
 * machine that already ran 000001 (dev DBs) — deleting the add file would
 * leave the column orphaned on those DBs with no migration record. The net
 * effect is: exactly ONE live threshold source ships (`envelope_settings`).
 *
 * `down()` re-adds the column symmetrically so a rollback of this migration
 * restores 000001's post-state.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->dropColumn('threshold_percent');
        });
    }

    public function down(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('threshold_percent')->nullable();
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
