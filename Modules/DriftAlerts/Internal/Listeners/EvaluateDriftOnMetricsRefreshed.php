<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

/**
 * Subscribes to the Recurring-side per-series metric-refresh event and
 * dispatches a queued DetectDriftAlertsJob for the affected series.
 *
 * The listener stays synchronous (no ShouldQueue on the listener
 * itself — the JOB is queued; double-queueing the listener would
 * defeat the unique-job key on (userId, seriesId)). One inbound event
 * → exactly one DetectDriftAlertsJob dispatch.
 *
 * Cross-module: imports Modules\Recurring\Public\Events only — never
 * Modules\Recurring\Internal. The crossModuleAccessGoesThroughPublic
 * arch invariant enforces this contract.
 */
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
