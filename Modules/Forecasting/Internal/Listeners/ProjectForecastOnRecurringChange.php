<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

/**
 * Subscribes to the four Recurring-side Public events that signal a
 * change in the recurring-series substrate (approve, cadence flip,
 * reject, metric refresh). Each upstream event fans out into three
 * baseline `ProjectForecastJob` dispatches — one per horizon
 * (30 / 60 / 90 days) — AND three dispatches per saved scenario the
 * user owns. The whole set of projections is invalidated because the
 * recurring-series substrate is the input to every projection.
 *
 * The downstream job's `ShouldBeUniqueUntilProcessing` lock collapses
 * concurrent triggers per `(userId, scenarioKey, horizon)` so even
 * dozens of upstream events in the same window do NOT produce
 * duplicate work.
 *
 * Cross-module: imports `Modules\Recurring\Public\Events` only — never
 * `Modules\Recurring\Internal`. The
 * `crossModuleAccessGoesThroughPublic` arch invariant enforces this.
 */
final readonly class ProjectForecastOnRecurringChange
{
    public function __construct(
        private Dispatcher $bus,
        private ScenarioQuery $scenarioQuery,
    ) {}

    public function handle(
        RecurringSeriesApproved|RecurringSeriesCadenceFlipped|RecurringSeriesRejected|RecurringSeriesMetricsRefreshed $event,
    ): void {
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
