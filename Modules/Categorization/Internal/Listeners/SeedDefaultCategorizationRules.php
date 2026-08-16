<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Modules\Categorization\Database\Seeders\DefaultCategorizationRuleSeeder;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;

// The User lookup lives here (not in the seeder) so the seeder's
// signature stays run(User $user). A UserInstalled event whose userId
// references no user row throws ModelNotFoundException, failing the
// install loud — the correct failure mode for a Core-module bug.
final class SeedDefaultCategorizationRules
{
    public function __construct(private readonly DefaultCategorizationRuleSeeder $seeder) {}

    public function handle(UserInstalled $event): void
    {
        // A device joining an existing account receives this data over sync;
        // seeding here would collide id-for-id with what the peer sends.
        if (! $event->seedsStarterData) {
            return;
        }

        $user = User::query()->findOrFail($event->userId);
        $this->seeder->run($user);
    }
}
