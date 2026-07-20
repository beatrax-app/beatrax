<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Forecasting\Models\ForecastScenario;

/**
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
