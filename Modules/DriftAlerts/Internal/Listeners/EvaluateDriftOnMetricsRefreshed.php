<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

// The listener itself is not queued: the job is, and queueing both would
// defeat the job's unique key on (userId, seriesId).
final readonly class EvaluateDriftOnMetricsRefreshed
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(RecurringSeriesMetricsRefreshed $event): void
    {
        $this->bus->dispatch(new DetectDriftAlertsJob(
            userId: $event->userId,
            recurringSeriesId: $event->recurringSeriesId,
        ));
    }
}
