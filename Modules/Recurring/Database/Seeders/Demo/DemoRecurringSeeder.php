<?php

declare(strict_types=1);

namespace Modules\Recurring\Database\Seeders\Demo;

use Modules\Core\Models\User;

/**
 * Stub for the demo-recurring seeder. Task 3 of plan 17-07b fills
 * the body with four RecurringSeries records (Spotify, Netflix,
 * gym, KPN) for the primary demo user.
 */
final class DemoRecurringSeeder
{
    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        return 0;
    }
}
