<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners;

use Modules\Sync\Internal\OpLog\PreSyncHistoryCapture;
use Modules\Sync\Public\Events\DeviceSyncEnabled;

// Captures everything that predates sync the moment it is switched on, so
// the first device to pair receives a full database rather than only what
// happens to change afterwards.
final readonly class BackfillOpLogOnSyncEnabled
{
    public function __construct(
        private PreSyncHistoryCapture $capture,
    ) {}

    public function handle(DeviceSyncEnabled $event): void
    {
        $this->capture->capture($event->userId);
    }
}
