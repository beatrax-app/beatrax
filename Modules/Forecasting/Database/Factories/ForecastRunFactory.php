<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Forecasting\Models\ForecastRun;

/**
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
