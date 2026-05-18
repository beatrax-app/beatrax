<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnDriftDismissed;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnRecurringChange;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnScenarioChange;
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

uses(RefreshDatabase::class);

/*
 * Confirms the Forecasting projection listeners fan each upstream event
 * out into ProjectForecastJob dispatches across both the baseline
 * (scenarioId=null) AND every saved scenario the user owns. Wave 4
 * extends the Wave 2 baseline-only fan-out — when a user has zero
 * saved scenarios the fan-out collapses to the 3 baseline horizons
 * (back-compat with Wave 2).
 *
 * ProjectForecastOnScenarioChange is the Forecasting-internal lifecycle
 * listener: it fans out baseline + the AFFECTED scenario only (not every
 * scenario), because the picker may surface a delta against the
 * baseline. Wave 5's ScenarioIsolationContractTest will exercise the
 * end-to-end isolation contract.
 */

function elUser(string $email = 'el-user@diederik.test'): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function elBaselineHorizonsFor(int $userId): array
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

it('fans RecurringSeriesApproved out to 3 baseline ProjectForecastJob dispatches when the user has zero scenarios', function (): void {
    Bus::fake();
    $user = elUser();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesApproved(seriesId: 99, userId: $user->id);

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elBaselineHorizonsFor($user->id))->toBe([30, 60, 90]);
});

it('fans RecurringSeriesCadenceFlipped out to 3 dispatches', function (): void {
    Bus::fake();
    $user = elUser();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesCadenceFlipped(
        seriesId: 99,
        userId: $user->id,
        oldCadence: 'monthly',
        newCadence: 'irregular',
    );

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elBaselineHorizonsFor($user->id))->toBe([30, 60, 90]);
});

it('fans RecurringSeriesRejected out to 3 dispatches', function (): void {
    Bus::fake();
    $user = elUser();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesRejected(seriesId: 99, userId: $user->id);

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elBaselineHorizonsFor($user->id))->toBe([30, 60, 90]);
});

it('fans RecurringSeriesMetricsRefreshed out to 3 dispatches', function (): void {
    Bus::fake();
    $user = elUser();

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesMetricsRefreshed(
        userId: $user->id,
        recurringSeriesId: 99,
        direction: 'expense',
        cadence: 'monthly',
        latestAmountMinor: -1199,
        latestCurrency: 'EUR',
    );

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elBaselineHorizonsFor($user->id))->toBe([30, 60, 90]);
});

it('fans DriftAlertDismissedCancelled out to 3 dispatches', function (): void {
    Bus::fake();
    $user = elUser();

    /** @var ProjectForecastOnDriftDismissed $listener */
    $listener = $this->app->make(ProjectForecastOnDriftDismissed::class);
    $event = new DriftAlertDismissedCancelled(
        userId: $user->id,
        driftAlertId: 123,
        recurringSeriesId: 99,
    );

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    expect(elBaselineHorizonsFor($user->id))->toBe([30, 60, 90]);
});

it('fans RecurringSeriesApproved out to 3 baseline + 3 per-scenario dispatches when the user owns one saved scenario', function (): void {
    Bus::fake();
    $user = elUser();
    $scenarioId = (int) $this->app->make(DatabaseManager::class)
        ->connection()
        ->table('forecast_scenarios')
        ->insertGetId([
            'user_id' => $user->id,
            'name' => 'Test scenario',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);

    /** @var ProjectForecastOnRecurringChange $listener */
    $listener = $this->app->make(ProjectForecastOnRecurringChange::class);
    $event = new RecurringSeriesApproved(seriesId: 99, userId: $user->id);

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 6);
    Bus::assertDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $j): bool => $j->scenarioId === $scenarioId && $j->horizonDays === 30);
});

it('ProjectForecastOnScenarioChange fans ScenarioCreated out to 6 dispatches (3 baseline + 3 affected)', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnScenarioChange $listener */
    $listener = $this->app->make(ProjectForecastOnScenarioChange::class);
    $event = new ScenarioCreated(userId: 7, scenarioId: 42, name: 'Test');

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 6);
    Bus::assertDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $j): bool => $j->scenarioId === null && $j->horizonDays === 30);
    Bus::assertDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $j): bool => $j->scenarioId === 42 && $j->horizonDays === 90);
});

it('ProjectForecastOnScenarioChange fans ScenarioMutated out to 6 dispatches', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnScenarioChange $listener */
    $listener = $this->app->make(ProjectForecastOnScenarioChange::class);
    $event = new ScenarioMutated(userId: 7, scenarioId: 42, mutationId: 99, kind: 'cancel_series');

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 6);
});

it('ProjectForecastOnScenarioChange fans ScenarioDeleted out to 3 baseline-only dispatches', function (): void {
    Bus::fake();

    /** @var ProjectForecastOnScenarioChange $listener */
    $listener = $this->app->make(ProjectForecastOnScenarioChange::class);
    $event = new ScenarioDeleted(userId: 7, scenarioId: 42);

    $listener->handle($event);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
    Bus::assertDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $j): bool => $j->scenarioId === null);
});
