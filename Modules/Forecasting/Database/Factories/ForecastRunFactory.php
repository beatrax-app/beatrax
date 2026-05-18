<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Forecasting\Models\ForecastRun;

/**
 * Eloquent factory for the forecast_runs audit table.
 *
 * Defaults to a `pending` run for horizon=30 days with no scenario
 * (baseline run). State factories walk the lifecycle:
 *   - pending() — the default; row reserved before dispatch
 *   - running() — the worker began handle()
 *   - complete() — success terminal
 *   - failed() — final retry exhausted terminal
 *
 * `user_id` defaults to 1 because the column is non-nullable; callers
 * override before persisting.
 *
 * @extends Factory<ForecastRun>
 */
final class ForecastRunFactory extends Factory
{
    /** @var class-string<ForecastRun> */
    protected $model = ForecastRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => 1,
            'scenario_id' => null,
            'horizon_days' => 30,
            'started_at' => null,
            'completed_at' => null,
            'status' => 'pending',
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    public function running(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
            'completed_at' => null,
        ]);
    }

    public function complete(): self
    {
        $now = CarbonImmutable::now();

        return $this->state(fn (array $attributes): array => [
            'status' => 'complete',
            'started_at' => $now->subSeconds(5),
            'completed_at' => $now,
        ]);
    }

    public function failed(): self
    {
        $now = CarbonImmutable::now();

        return $this->state(fn (array $attributes): array => [
            'status' => 'failed',
            'started_at' => $now->subSeconds(5),
            'completed_at' => $now,
        ]);
    }
}
