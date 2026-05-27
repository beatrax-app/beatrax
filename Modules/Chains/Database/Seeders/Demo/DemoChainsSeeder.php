<?php

declare(strict_types=1);

namespace Modules\Chains\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

/**
 * Stub for the demo-chains seeder. Task 3 of plan 17-07b fills the
 * body with two pre-resolved chain examples (PayPal→ASN funding
 * chain and ICS→ASN monthly bulk settlement).
 */
final class DemoChainsSeeder
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
