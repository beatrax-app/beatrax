<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Services\ScenarioQuery;

final readonly class ProjectForecastOnDriftDismissed
{
    public function __construct(
        private Dispatcher $bus,
        private ScenarioQuery $scenarioQuery,
    ) {}

    public function handle(DriftAlertDismissedCancelled $event): void
    {
        foreach (ForecastHorizon::days() as $horizon) {
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
            foreach (ForecastHorizon::days() as $horizon) {
                $this->bus->dispatch(new ProjectForecastJob(
                    userId: $event->userId,
                    scenarioId: $scenario->id,
                    horizonDays: $horizon,
                ));
            }
        }
    }
}
