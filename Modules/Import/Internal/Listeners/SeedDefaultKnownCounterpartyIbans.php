<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;

// The User lookup lives here so the seeder keeps its run(User $user)
// signature; a userId that no longer resolves is a Core bug, so
// findOrFail() is deliberate.
final class SeedDefaultKnownCounterpartyIbans
{
    public function __construct(private readonly DefaultKnownCounterpartyIbansSeeder $seeder) {}

    public function handle(UserInstalled $event): void
    {
        $user = User::query()->findOrFail($event->userId);
        $this->seeder->run($user);
    }
}
