<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Budgets\Internal\Jobs\EmitBudgetNudgesJob;

// The seam the notification passes reach this module through, so nothing
// outside Budgets has to name EmitBudgetNudgesJob to ask for a user's nudges.
final readonly class BudgetNudgeDispatch
{
    public function __construct(
        private Dispatcher $bus,
    ) {}

    public function forUser(int $userId): void
    {
        $this->bus->dispatch(new EmitBudgetNudgesJob($userId));
    }

    // In-process, for a caller whose own session holds the app-lock key. The
    // queue worker builds its own empty session, so a nudge queued from a
    // request would be sealed by nobody and refused all over again.
    public function forUserNow(int $userId): void
    {
        $this->bus->dispatchSync(new EmitBudgetNudgesJob($userId));
    }
}
