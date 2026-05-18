<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

/**
 * Subscribes to the four Recurring-side Public events that signal a
 * change in the recurring-series substrate (approve, cadence flip,
 * reject, metric refresh). Each upstream event fans out into three
 * baseline `ProjectForecastJob` dispatches — one per horizon
 * (30 / 60 / 90 days). Wave 4 (Plan 10-05) extends this fan-out to
 * also dispatch per saved scenario via `ScenarioQuery::forUser`.
 *
 * The downstream job's `ShouldBeUniqueUntilProcessing` lock collapses
 * concurrent triggers per `(userId, baseline, horizon)` — multiple
 * upstream events in the same window do NOT produce duplicate work,
 * only the first dispatch survives until the worker begins
 * processing.
 *
 * Cross-module: imports `Modules\Recurring\Public\Events` only — never
 * `Modules\Recurring\Internal`. The
 * `crossModuleAccessGoesThroughPublic` arch invariant enforces this.
 */
final readonly class ProjectForecastOnRecurringChange
{
    public function __construct(private Dispatcher $bus) {}

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
    }
}
