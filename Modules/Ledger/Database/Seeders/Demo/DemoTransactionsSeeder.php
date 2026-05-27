<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

/**
 * Stub for the synthetic-transactions seeder. Task 2 of plan 17-07b
 * fills the body; the empty `run()` here lets DemoSeedCommand register
 * + boot without referencing a non-existent class.
 */
final class DemoTransactionsSeeder
{
    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        return 0;
    }
}
