<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Listeners;

use Illuminate\Contracts\Container\Container;
use Modules\Budgets\Public\Services\EnvelopeActivationService;
use Modules\Core\Public\Events\UserInstalled;

// The cutover migration is a walk over the readers who existed when it ran, and
// `beatrax:install` migrates before it creates its user — so on a fresh install
// that walk saw an empty table and the anchor stayed null forever.
final readonly class ActivateEnvelopeBudgetingOnInstall
{
    // Resolved on demand, not injected: the graph below reaches PeriodQuery,
    // which is per-resolve because it reads the current user. A listener built
    // once would freeze the installing user's context and hand it to the
    // household partner AddUserAction installs later in the same process.
    public function __construct(private Container $container) {}

    // The anchor is a synced, last-writer-wins column: a joining device stamping
    // today would carry a genesis newer than the peer's back over sync, and
    // every month of their history would fall below it.
    public function handle(UserInstalled $event): void
    {
        if (! $event->seedsStarterData) {
            return;
        }

        $this->container->make(EnvelopeActivationService::class)->activateForUser($event->userId);
    }
}
