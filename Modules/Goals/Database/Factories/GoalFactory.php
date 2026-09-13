<?php

declare(strict_types=1);

namespace Modules\Goals\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Ledger\Public\Enums\Currency;

// user_id stays null so callers must supply it: a default would silently
// attribute goals to a user the test never created.
/**
 * @extends Factory<Goal>
 */
final class GoalFactory extends Factory
{
    /** @var class-string<Goal> */
    protected $model = Goal::class;

    // The id is minted here because GoalWriter mints it: a goal's row id is
    // random_int(1, PHP_INT_MAX), so all but one in a thousand is past 2^53 and
    // no JavaScript number can carry it. An autoincrement 1 is a magnitude the
    // field never produces, and it hid a dead attribution button.
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => DeviceMintedRowId::mint(),
            'user_id' => null,
            'name' => $this->faker->word(),
            'target_minor' => 100000,
            'target_currency' => Currency::Eur->value,
            'start_date' => CarbonImmutable::now()->toDateString(),
            'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
            'status' => GoalStatus::Active->value,
        ];
    }
}
