<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Anomaly\Internal\Jobs\ReviveExpiredAnomalySnoozesJob;

// Global rather than per-user: the state machine resolves the owner from the
// row. AnomalyAlertQuery::openForUser already surfaces expired-but-snoozed
// rows between sweeps, so a missed tick never shows a stale list.
final class ReviveAnomalySnoozesCommand extends Command
{
    /** @var string */
    protected $signature = 'anomaly:revive-snoozes';

    /** @var string */
    protected $description = 'Reopen anomaly alerts whose snooze has elapsed.';

    public function __construct(
        private readonly Dispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->bus->dispatch(new ReviveExpiredAnomalySnoozesJob);

        return self::SUCCESS;
    }
}
