<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Forecasting\Models\ForecastScenario;

/**
 * Eloquent factory for the forecast_scenarios table.
 *
 * Defaults to a null user_id; callers override before persisting. The
 * UNIQUE(user_id, name) constraint means the scenario name uses a
 * faker token so concurrent factory calls do not collide.
 *
 * @extends Factory<ForecastScenario>
 */
final class ForecastScenarioFactory extends Factory
{
    /** @var class-string<ForecastScenario> */
    protected $model = ForecastScenario::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => 'Scenario '.$this->faker->unique()->word().' '.$this->faker->randomNumber(4),
            'description' => null,
        ];
    }
}
