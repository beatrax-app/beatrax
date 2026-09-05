<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Modules\Forecasting\Internal\Support\ForecastReprojection;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

final readonly class ProjectForecastOnRecurringChange
{
    public function __construct(private ForecastReprojection $reprojection) {}

    public function handle(
        RecurringSeriesApproved|RecurringSeriesCadenceFlipped|RecurringSeriesRejected|RecurringSeriesMetricsRefreshed $event,
    ): void {
        $this->reprojection->everything($event->userId);
    }
}
