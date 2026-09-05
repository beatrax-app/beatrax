<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnPeerRowsApplied;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Sync\Public\Events\PeerRowsApplied;

uses(RefreshDatabase::class);

// forecast_runs and forecast_shortfall_windows are derived and device-local,
// and every input the eight local listeners fire on travels. So a series a
// household member approved reached this device's tables and none of its
// forecasts, until the daily sweep caught up as much as a day later.

function reprojectingPeerUser(): User
{
    return User::query()->create([
        'username' => 'reproject-peer-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function reprojectingPeerListener(): ProjectForecastOnPeerRowsApplied
{
    // Resolved AFTER Bus::fake(), which rebinds the dispatcher the listener's
    // collaborator takes at construction.
    /** @var ProjectForecastOnPeerRowsApplied $listener */
    $listener = app()->make(ProjectForecastOnPeerRowsApplied::class);

    return $listener;
}

function reprojectingPeerScenario(int $userId, string $name): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('forecast_scenarios')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'created_at' => '2026-09-01 09:00:00',
        'updated_at' => '2026-09-01 09:00:00',
    ]);
}

function reprojectingPeerHorizonCount(): int
{
    return count(ForecastHorizon::days());
}

it('re-projects every line when a recurring series arrives from a peer', function (): void {
    Bus::fake();
    $user = reprojectingPeerUser();

    reprojectingPeerListener()->handle(new PeerRowsApplied(
        userId: (int) $user->id,
        updated: ['recurring_series' => [17]],
    ));

    Bus::assertDispatchedTimes(ProjectForecastJob::class, reprojectingPeerHorizonCount());
    Bus::assertDispatched(
        ProjectForecastJob::class,
        fn (ProjectForecastJob $job): bool => $job->userId === (int) $user->id
            && $job->scenarioId === null
            && $job->horizonDays === 30,
    );
});

it('re-projects every line for a drift alert or a scenario mutation', function (string $table): void {
    Bus::fake();
    $user = reprojectingPeerUser();
    reprojectingPeerScenario((int) $user->id, 'Saved scenario');

    reprojectingPeerListener()->handle(new PeerRowsApplied(
        userId: (int) $user->id,
        created: [$table => [3]],
    ));

    // The baseline plus the one saved scenario: a mutation row names its
    // scenario in a column the announcement does not carry.
    Bus::assertDispatchedTimes(ProjectForecastJob::class, reprojectingPeerHorizonCount() * 2);
})->with(['drift_alerts', 'forecast_scenario_mutations']);

it('re-projects the baseline and the arriving scenario only', function (): void {
    Bus::fake();
    $user = reprojectingPeerUser();
    $arriving = reprojectingPeerScenario((int) $user->id, 'Arriving scenario');
    $untouched = reprojectingPeerScenario((int) $user->id, 'Untouched scenario');

    reprojectingPeerListener()->handle(new PeerRowsApplied(
        userId: (int) $user->id,
        created: ['forecast_scenarios' => [$arriving]],
    ));

    Bus::assertDispatchedTimes(ProjectForecastJob::class, reprojectingPeerHorizonCount() * 2);
    Bus::assertDispatched(
        ProjectForecastJob::class,
        fn (ProjectForecastJob $job): bool => $job->scenarioId === $arriving && $job->horizonDays === 60,
    );
    Bus::assertNotDispatched(
        ProjectForecastJob::class,
        fn (ProjectForecastJob $job): bool => $job->scenarioId === $untouched,
    );
});

it('re-projects the baseline alone for a scenario a peer deleted', function (): void {
    Bus::fake();
    $user = reprojectingPeerUser();
    reprojectingPeerScenario((int) $user->id, 'Still here');

    reprojectingPeerListener()->handle(new PeerRowsApplied(
        userId: (int) $user->id,
        deleted: ['forecast_scenarios' => [9]],
    ));

    Bus::assertDispatchedTimes(ProjectForecastJob::class, reprojectingPeerHorizonCount());
    Bus::assertDispatched(
        ProjectForecastJob::class,
        fn (ProjectForecastJob $job): bool => $job->scenarioId === null,
    );
});

it('queues nothing for a table the projection does not follow', function (): void {
    Bus::fake();
    $user = reprojectingPeerUser();

    // transactions and accounts are the tripwires: the projection reads both,
    // and no local writer re-projects on an import or a balance either. A
    // first sync carrying thousands of rows must queue nothing.
    reprojectingPeerListener()->handle(new PeerRowsApplied(
        userId: (int) $user->id,
        created: ['transactions' => [1, 2, 3]],
        updated: ['accounts' => [4]],
        deleted: ['tax_transaction_tags' => [5]],
    ));

    Bus::assertNotDispatched(ProjectForecastJob::class);
});
