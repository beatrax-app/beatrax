<?php

declare(strict_types=1);

namespace Modules\Pots\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotStatus;

// user_id and account_id stay null so callers must supply them: defaults would
// silently attach pots to rows the test never created.
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
