<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

// The listener stays synchronous (no ShouldQueue on the listener itself —
// the job is queued; double-queueing the listener would defeat the
// unique-job key on (userId, seriesId)). One inbound event maps to exactly
// one DetectDriftAlertsJob dispatch.
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
