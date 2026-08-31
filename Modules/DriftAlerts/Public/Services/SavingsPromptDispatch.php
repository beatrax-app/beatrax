<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Internal\Jobs\EmitSavingsPromptsJob;

// The seam the notification pass reaches this module through, so DriftAlerts
// stays a trigger module that never imports Notifications.
final readonly class SavingsPromptDispatch
{
    public function __construct(
        private Dispatcher $bus,
    ) {}

    public function forUser(int $userId): void
    {
        $this->bus->dispatch(new EmitSavingsPromptsJob($userId));
    }

    // In-process, for a caller whose own session holds the app-lock key: a
    // prompt queued from a request would be sealed by a worker that has no
    // session of its own, which is where the last one was refused.
    public function forUserNow(int $userId): void
    {
        $this->bus->dispatchSync(new EmitSavingsPromptsJob($userId));
    }
}
