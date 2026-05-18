<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Forecasting\Public\Events\ScenarioMutated;

/**
 * Subscribes to the three Forecasting Public scenario lifecycle
 * events (created / mutated / deleted) and fans out per-horizon
 * `ProjectForecastJob` dispatches for the AFFECTED scenario plus the
 * baseline.
 *
 * Important: the listener intentionally does NOT fan out to every
 * other saved scenario the user owns — that work is reserved for
 * upstream substrate events (Recurring approvals, drift dismissals)
 * which DO require every scenario to be re-projected. A
 * `ScenarioMutated` event only invalidates the affected scenario and
 * the baseline (because the picker may surface a delta against the
 * baseline), so the fan-out is six dispatches per event:
 * 3 baseline horizons + 3 affected-scenario horizons.
 *
 * For `ScenarioDeleted` events the listener dispatches the three
 * baseline horizons only; the affected scenario's runs were already
 * wiped by the cascade-on-delete FK from Plan 10-02.
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
