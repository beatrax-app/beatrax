<?php

declare(strict_types=1);

namespace Modules\Goals\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;

// user_id stays null so callers must supply it: a default would silently
// attribute goals to a user the test never created.
/**
 * @extends Factory<Goal>
 */
final class GoalFactory extends Factory
{
    /** @var class-string<Goal> */
    protected $model = Goal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => $this->faker->word(),
            'target_minor' => 100000,
            'target_currency' => 'EUR',
            'start_date' => CarbonImmutable::now()->toDateString(),
            'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
            'status' => GoalStatus::Active->value,
        ];
    }
}
