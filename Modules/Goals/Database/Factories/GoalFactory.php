<?php

declare(strict_types=1);

namespace Modules\Goals\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Goals\Models\Goal;

/**
 * Eloquent factory for the goals table.
 *
 * Defaults to an active, unlinked goal with a 100 EUR target starting today
 * and finishing in one year. Callers override individual fields to construct
 * the specific scenario under test.
 *
 * `user_id` is left null in the default definition so callers always supply it
 * explicitly — silently assuming user_id=1 creates hard-to-debug cross-test
 * pollution in RefreshDatabase suites.
 *
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
            'account_id' => null,
            'name' => $this->faker->word(),
            'target_minor' => 100000,   // 1 000.00 EUR in minor units (cents)
            'target_currency' => 'EUR',
            'start_date' => CarbonImmutable::now()->toDateString(),
            'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
            'status' => 'active',
        ];
    }
}
