<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the category_budgets table — one monthly spending budget per
 * (user, category).
 *
 * Money follows the project-wide signed BIGINT minor-units convention with a
 * sibling 3-letter `currency` column; `budget_minor` is always positive (a
 * budget is a ceiling, not a signed flow). `period_type` is a forward-looking
 * seam — only `monthly` ships in v1, but the column lets a weekly/yearly
 * cadence land later without a schema change. The UNIQUE `(user_id,
 * category_id)` constraint makes "set a budget" an idempotent upsert: a
 * category can carry at most one budget per user.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('category_budgets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('period_type', 16)->default('monthly');
            $table->bigInteger('budget_minor');
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique(['user_id', 'category_id'], 'category_budgets_user_category_uniq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('category_budgets');
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
