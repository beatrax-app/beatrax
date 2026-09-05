<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Modules\Forecasting\Internal\Support\ForecastReprojection;
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Forecasting\Public\Events\ScenarioMutated;

final readonly class ProjectForecastOnScenarioChange
{
    public function __construct(private ForecastReprojection $reprojection) {}

    public function handle(ScenarioCreated|ScenarioMutated|ScenarioDeleted $event): void
    {
        $this->reprojection->baseline($event->userId);

        if ($event instanceof ScenarioDeleted) {
            return;
        }

        $this->reprojection->scenario($event->userId, $event->scenarioId);
    }
}
