<?php

declare(strict_types=1);

namespace Modules\Pots\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Pots\Models\Pot;

/**
 * Eloquent factory for the pots table.
 *
 * Defaults to an active, unlinked EUR pot. Callers override individual fields
 * to construct the specific scenario under test.
 *
 * `user_id` and `account_id` are left null in the default definition so callers
 * always supply them explicitly — silently assuming user_id=1 creates hard-to-
 * debug cross-test pollution in RefreshDatabase suites. (GoalFactory line 34
 * establishes this convention.)
 *
 * @extends Factory<Pot>
 */
final class PotFactory extends Factory
{
    /** @var class-string<Pot> */
    protected $model = Pot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,     // always supplied explicitly in tests
            'account_id' => null,     // always supplied explicitly in tests
            'goal_id' => null,
            'category_id' => null,
            'name' => $this->faker->word(),
            'currency' => 'EUR',
            'status' => 'active',
        ];
    }
}
