<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnDriftDismissed;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnRecurringChange;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnScenarioChange;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

/*
 * Confirms the Forecasting projection listeners fan each upstream event
 * out into THREE ProjectForecastJob dispatches — one per horizon
 * (30 / 60 / 90), all with scenarioId=null (baseline-only fan-out for
 * Wave 2). Wave 4 (Plan 10-05) extends to scenario fan-out without
 * changing the upstream subscription wiring.
 *
 * The ProjectForecastOnScenarioChange listener stays a Wave 0 throw
 * scaffold until Wave 4 lands the scenario lifecycle events.
 */

function elDispatchedHorizonsFor(int $userId): array
{
    $horizons = [];
    Bus::assertDispatched(ProjectForecastJob::class, function (ProjectForecastJob $job) use ($userId, &$horizons): bool {
        if ($job->userId !== $userId) {
            return false;
        }
        if ($job->scenarioId !== null) {
            return false;
        }
        $horizons[] = $job->horizonDays;

        return true;
    });
    sort($horizons);

    return $horizons;
}

it('fans RecurringSeriesApproved out to 3 baseline ProjectForecastJob dispatches', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesApproved(seriesId: 99, userId: 7);

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elDispatchedHorizonsFor(7))->toBe([30, 60, 90]);
});

it('fans RecurringSeriesCadenceFlipped out to 3 dispatches', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesCadenceFlipped(
        seriesId: 99,
        userId: 7,
        oldCadence: 'monthly',
        newCadence: 'irregular',
    );

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elDispatchedHorizonsFor(7))->toBe([30, 60, 90]);
});

it('fans RecurringSeriesRejected out to 3 dispatches', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesRejected(seriesId: 99, userId: 7);

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elDispatchedHorizonsFor(7))->toBe([30, 60, 90]);
});

it('fans RecurringSeriesMetricsRefreshed out to 3 dispatches', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesMetricsRefreshed(
        userId: 7,
        recurringSeriesId: 99,
        direction: 'expense',
        cadence: 'monthly',
        latestAmountMinor: -1199,
        latestCurrency: 'EUR',
    );

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elDispatchedHorizonsFor(7))->toBe([30, 60, 90]);
});

it('fans DriftAlertDismissedCancelled out to 3 dispatches', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnDriftDismissed $listener */
    $listener = $this->app->make(ProjectForecastOnDriftDismissed::class);
    $event = new DriftAlertDismissedCancelled(
        userId: 7,
        driftAlertId: 123,
        recurringSeriesId: 99,
    );

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elDispatchedHorizonsFor(7))->toBe([30, 60, 90]);
});

it('keeps the Wave 0 scaffold body in place on ProjectForecastOnScenarioChange (Wave 4 swap-in target)', function (): void {
    /** @var ProjectForecastOnScenarioChange $listener */
    $listener = $this->app->make(ProjectForecastOnScenarioChange::class);

    $call = fn (): mixed => $listener->handle(new stdClass);

    expect($call)->toThrow(RuntimeException::class);
});
