<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;

/**
 * Subscribes to the DriftAlerts-side Public event that signals the
 * user has dismissed an alert as a real-world cancellation. The
 * cancelled series falls out of every active forecast projection for
 * the user: each event fans out into three baseline
 * `ProjectForecastJob` dispatches — one per horizon (30 / 60 / 90
 * days).
 *
 * Cross-module: imports `Modules\DriftAlerts\Public\Events` only —
 * never `Modules\DriftAlerts\Internal`.
 */
final readonly class ProjectForecastOnDriftDismissed
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(DriftAlertDismissedCancelled $event): void
    {
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $event->userId,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }
    }
}
