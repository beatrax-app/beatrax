<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds `category_budgets.threshold_percent` (D-20) — the per-budget
 * notification threshold Req 6's over-budget nudge trigger (plan 18-07)
 * reads. Nullable with NO database default: null means "use the D-20 90%
 * default", which keeps the single default in ONE place
 * (`BudgetProgressQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT`) rather than
 * duplicating it into the schema, so a future default change applies to
 * every existing row without a data migration.
 *
 * This is a DIFFERENT concept from `BudgetProgressQuery::NEAR_THRESHOLD`
 * (the progress-bar colour bucket, unrelated and untouched here).
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('threshold_percent')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('category_budgets', static function (Blueprint $table): void {
            $table->dropColumn('threshold_percent');
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
