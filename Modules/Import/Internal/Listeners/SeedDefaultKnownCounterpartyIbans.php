<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;

/**
 * Resolves the installed user from the event's id payload and runs
 * the per-user known-counterparty-IBAN seeder. The User lookup lives
 * here (not in the seeder) so the seeder signature stays
 * `run(User $user)` — matching the per-user seeder convention. A
 * `userId` that no longer resolves indicates a Core-module bug;
 * `findOrFail()` throws ModelNotFoundException, which is the correct
 * fail-loud behavior for a UserInstalled event referencing an unknown
 * user row.
 */
final class SeedDefaultKnownCounterpartyIbans
{
    public function __construct(private readonly DefaultKnownCounterpartyIbansSeeder $seeder) {}

    public function handle(UserInstalled $event): void
    {
        $user = User::query()->findOrFail($event->userId);
        $this->seeder->run($user);
    }
}
