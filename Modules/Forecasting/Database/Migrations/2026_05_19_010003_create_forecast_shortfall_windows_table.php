<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Pre-computed shortfall windows written by ProjectForecastJob. The
 * dashboard "Forecast highlights" tile reads from this table for the
 * lowest-projected-balance line; the /forecast page reads from it for
 * the per-account shortfall ribbon.
 *
 * Each row pins a contiguous (starts_at, ends_at) window during which
 * the projected balance dipped below the effective per-account buffer.
 * `lowest_balance_minor` is signed (the lowest point hit inside the
 * window) and `buffer_used_minor` captures the buffer effective at
 * detection time — Phase 9's honest-audit precedent so a later buffer
 * edit cannot silently rewrite the historical shortfall narrative.
 *
 * `scenario_id` is nullable: NULL = baseline projection's shortfall;
 * non-NULL = a scenario projection's shortfall, used by the
 * scenario-vs-baseline diff read.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('forecast_shortfall_windows', static function (Blueprint $table): void {
            $table->id();
            // user_id is non-nullable (chain_resolution_runs + forecast_runs
            // precedent): every shortfall window belongs to exactly one user,
            // and a NULL would silently escape per-user filters.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('forecast_scenarios')->cascadeOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->bigInteger('lowest_balance_minor');
            $table->string('currency', 3);
            $table->bigInteger('buffer_used_minor');
            $table->timestamps();

            $table->index(['user_id', 'account_id', 'starts_at']);
            $table->index(['user_id', 'scenario_id']);
            $table->index(['user_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_shortfall_windows');
    }
};
