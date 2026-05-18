<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Services\ScenarioQuery;

/**
 * Subscribes to the DriftAlerts-side Public event that signals the
 * user has dismissed an alert as a real-world cancellation. The
 * cancelled series falls out of every active forecast projection for
 * the user: each event fans out into three baseline
 * `ProjectForecastJob` dispatches AND three dispatches per saved
 * scenario the user owns. The whole projection surface is
 * invalidated because the cancellation drops the series from the
 * approved-recurring-series substrate every projection consumes.
 *
 * Cross-module: imports `Modules\DriftAlerts\Public\Events` only —
 * never `Modules\DriftAlerts\Internal`.
 */
final readonly class ProjectForecastOnDriftDismissed
{
    public function __construct(
        private Dispatcher $bus,
        private ScenarioQuery $scenarioQuery,
    ) {}

    public function handle(DriftAlertDismissedCancelled $event): void
    {
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $event->userId,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }

        $user = User::query()->where('id', $event->userId)->first();
        if (! $user instanceof User) {
            return;
        }
        foreach ($this->scenarioQuery->forUser($user) as $scenario) {
            foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
                $this->bus->dispatch(new ProjectForecastJob(
                    userId: $event->userId,
                    scenarioId: $scenario->id,
                    horizonDays: $horizon,
                ));
            }
        }
    }
}
