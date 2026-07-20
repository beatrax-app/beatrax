<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;

// The User lookup lives here (not in the seeder) so the seeder keeps
// its per-user `run(User $user)` signature; findOrFail() fail-loudly
// throws on a userId that no longer resolves (a Core-module bug).
final class SeedDefaultKnownCounterpartyIbans
{
    public function __construct(private readonly DefaultKnownCounterpartyIbansSeeder $seeder) {}

    public function handle(UserInstalled $event): void
    {
        $user = User::query()->findOrFail($event->userId);
        $this->seeder->run($user);
    }
}
