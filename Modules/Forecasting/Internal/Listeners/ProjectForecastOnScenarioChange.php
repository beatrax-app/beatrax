<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Forecasting\Public\Events\ScenarioMutated;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ProjectForecastOnScenarioChange
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(ScenarioCreated|ScenarioMutated|ScenarioDeleted $event): void
    {
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $event->userId,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }

        if ($event instanceof ScenarioDeleted) {
            return;
        }

        foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $event->userId,
                scenarioId: $event->scenarioId,
                horizonDays: $horizon,
            ));
        }
    }
}
