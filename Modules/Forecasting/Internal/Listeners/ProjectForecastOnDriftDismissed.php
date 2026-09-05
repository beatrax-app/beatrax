<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Support\ForecastReprojection;

final readonly class ProjectForecastOnDriftDismissed
{
    public function __construct(private ForecastReprojection $reprojection) {}

    public function handle(DriftAlertDismissedCancelled $event): void
    {
        $this->reprojection->everything($event->userId);
    }
}
