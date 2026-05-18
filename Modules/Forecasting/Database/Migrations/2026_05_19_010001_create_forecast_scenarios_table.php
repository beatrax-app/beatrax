<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Saved what-if scenarios. Each scenario is a named container for a
 * list of mutations the user wants to model against the baseline
 * forecast. Strictly walled off from the transaction substrate —
 * no JOIN onto transactions / recurring_series / chain_links is
 * permitted (enforced by the noScenarioMutationsJoinedToTransactionQueries
 * arch test).
 *
 * `user_id` is nullable + cascade-on-delete: scenarios are user-owned
 * and deleting the user wipes their scenarios cleanly.
 *
 * Indexes:
 *   - UNIQUE(user_id, name) — scenario names are unique per-user, so
 *     the rename action has a deterministic conflict surface.
 *   - INDEX(user_id, created_at) — scenario picker sorts by recency.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('forecast_scenarios', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_scenarios');
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
