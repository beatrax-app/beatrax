<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * A single mutation inside a saved scenario (cancel a series, add a
 * one-off, etc.). The `payload` column is a typed JSON envelope keyed
 * by `kind`; the Eloquent ScenarioMutationPayloadCast hydrates it into
 * the appropriate ScenarioMutationPayload subtype.
 *
 * Allowed kinds (enum-by-string, validated at the cast layer):
 *   - cancel_series
 *   - add_one_off
 *   - add_recurring
 *   - change_series_amount
 *   - shift_series_date
 *
 * `target_series_id` is nullable and NOT FK-constrained: `add_one_off`
 * and `add_recurring` mutations carry no target series, and we avoid a
 * hard cross-module FK because `recurring_series` lives in another
 * module. Runtime existence is validated by the Public Action.
 *
 * `payload` is a JSON column (TEXT on SQLite with JSON1 functions
 * available). The typed cast happens at the ORM boundary, not the
 * schema boundary.
 *
 * Never JOINed onto the transaction substrate — see
 * noScenarioMutationsJoinedToTransactionQueries arch test.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('forecast_scenario_mutations', static function (Blueprint $table): void {
            $table->id();
            // user_id is non-nullable (chain_resolution_runs + forecast_runs
            // precedent): every mutation belongs to exactly one user, and a
            // NULL would silently escape per-user filters.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('forecast_scenario_id')->constrained('forecast_scenarios')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->unsignedBigInteger('target_series_id')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['user_id', 'forecast_scenario_id']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_scenario_mutations');
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
