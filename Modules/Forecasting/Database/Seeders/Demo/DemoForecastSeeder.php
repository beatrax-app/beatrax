<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Seeders\Demo;

use Modules\Core\Models\User;

/**
 * Stub for the demo-forecast seeder. Task 3 of plan 17-07b fills the
 * body with a single "Base Case" ForecastScenario for the primary
 * demo user — a 60-day projection window the README screenshot of
 * the cash-flow chart hangs off.
 */
final class DemoForecastSeeder
{
    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        return 0;
    }
}
