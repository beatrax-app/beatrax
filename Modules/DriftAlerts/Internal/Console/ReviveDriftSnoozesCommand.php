<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Internal\Jobs\RevivedExpiredDriftSnoozesJob;

// Global rather than per-user: the state-machine call resolves the owner from
// the row itself. DriftAlertQuery::openForUser already surfaces expired-but-
// snoozed rows between sweeps, so a missed tick never shows a stale count.
final class ReviveDriftSnoozesCommand extends Command
{
    /** @var string */
    protected $signature = 'drift-alerts:revive-snoozes';

    /** @var string */
    protected $description = 'Reopen drift alerts whose snooze has elapsed.';

    public function __construct(
        private readonly Dispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->bus->dispatch(new RevivedExpiredDriftSnoozesJob);

        return self::SUCCESS;
    }
}
