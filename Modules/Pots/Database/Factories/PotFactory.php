<?php

declare(strict_types=1);

namespace Modules\Pots\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotStatus;

// user_id and account_id are left null in the default definition so callers
// always supply them explicitly — silently assuming user_id=1 creates
// hard-to-debug cross-test pollution in RefreshDatabase suites.
/**
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
            'user_id' => null,
            'account_id' => null,
            'goal_id' => null,
            'category_id' => null,
            'name' => $this->faker->word(),
            'currency' => 'EUR',
            'status' => PotStatus::Active->value,
        ];
    }
}
