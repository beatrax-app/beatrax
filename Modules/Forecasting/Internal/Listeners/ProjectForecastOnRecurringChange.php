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
 * @link ../../../../.docs/features/forecasting/architecture.md
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
