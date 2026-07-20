<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Forecasting\Models\ForecastShortfallWindow;

/**
 * @extends Factory<ForecastShortfallWindow>
 */
final class ForecastShortfallWindowFactory extends Factory
{
    /** @var class-string<ForecastShortfallWindow> */
    protected $model = ForecastShortfallWindow::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+1 day', '+10 days')->format('Y-m-d');
        $end = $this->faker->dateTimeBetween('+11 days', '+25 days')->format('Y-m-d');

        return [
            'user_id' => null,
            'account_id' => null,
            'scenario_id' => null,
            'starts_at' => $start,
            'ends_at' => $end,
            'lowest_balance_minor' => 12000,
            'currency' => 'EUR',
            'buffer_used_minor' => 50000,
        ];
    }

    public function forBaseline(): self
    {
        return $this->state(fn (array $attributes): array => [
            'scenario_id' => null,
        ]);
    }

    public function forScenario(int $scenarioId): self
    {
        return $this->state(fn (array $attributes): array => [
            'scenario_id' => $scenarioId,
        ]);
    }
}
