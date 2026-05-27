<?php

declare(strict_types=1);

namespace Modules\Counterparties\Database\Seeders\Demo;

use Modules\Core\Models\User;

/**
 * Stub for the demo-counterparties seeder. Task 3 of plan 17-07b
 * fills the body by iterating the seeded demo transactions and
 * invoking the production `CounterpartyResolver` for each.
 */
final class DemoCounterpartiesSeeder
{
    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        return 0;
    }
}
