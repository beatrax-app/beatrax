<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;
use Modules\DriftAlerts\Internal\Listeners\EvaluateDriftOnMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

it('dispatches exactly one DetectDriftAlertsJob per inbound event', function (): void {
    Bus::fake();

    /** @var EvaluateDriftOnMetricsRefreshed $listener */
    $listener = $this->app->make(EvaluateDriftOnMetricsRefreshed::class);

    $event = new RecurringSeriesMetricsRefreshed(
        userId: 42,
        recurringSeriesId: 7,
        direction: 'expense',
        cadence: 'monthly',
        latestAmountMinor: -1149,
        latestCurrency: 'EUR',
    );

    $listener->handle($event);

    Bus::assertDispatchedTimes(DetectDriftAlertsJob::class, 1);
    Bus::assertDispatched(DetectDriftAlertsJob::class, fn (DetectDriftAlertsJob $job): bool => $job->userId === 42 && $job->recurringSeriesId === 7);
});
