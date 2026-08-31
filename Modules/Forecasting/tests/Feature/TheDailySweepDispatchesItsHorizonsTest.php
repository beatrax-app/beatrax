<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// Nothing else in the suite ran the sweep, so it referenced a constant that does
// not exist and fatalled on the first user for as long as it shipped: no
// projection was ever queued by the scheduler. It is an artisan command now, so
// this runs exactly what the desktop scheduler and the phone's WorkManager both
// invoke.

const DAILY_SWEEP_NAME = 'forecasting.daily-sweep';

const DAILY_SWEEP_COMMAND = 'forecasting:project';

function dailySweepEvent(): Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if ($event->description === DAILY_SWEEP_NAME) {
            return $event;
        }
    }

    throw new RuntimeException('The '.DAILY_SWEEP_NAME.' schedule entry is not registered.');
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sweep-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
});

it('schedules the sweep as the command name the background runner on a phone invokes', function (): void {
    $event = dailySweepEvent();

    expect((string) $event->command)->toContain(DAILY_SWEEP_COMMAND);
    expect($event->expression)->toBe('0 0 * * *');
});

it('dispatches one baseline projection per horizon when the sweep command runs', function (): void {
    Bus::fake();

    Artisan::call(DAILY_SWEEP_COMMAND);

    foreach (ForecastHorizon::days() as $horizon) {
        Bus::assertDispatched(
            ProjectForecastJob::class,
            fn (ProjectForecastJob $job): bool => $job->userId === $this->user->id
                && $job->scenarioId === null
                && $job->horizonDays === $horizon,
        );
    }

    Bus::assertDispatchedTimes(ProjectForecastJob::class, count(ForecastHorizon::days()));
});

it('fans the sweep out over the saved scenarios as well as the baseline', function (): void {
    $scenarioId = app(CreateScenario::class)($this->user, 'Sweep scenario');

    Bus::fake();

    Artisan::call(DAILY_SWEEP_COMMAND);

    foreach (ForecastHorizon::days() as $horizon) {
        Bus::assertDispatched(
            ProjectForecastJob::class,
            fn (ProjectForecastJob $job): bool => $job->scenarioId === $scenarioId
                && $job->horizonDays === $horizon,
        );
    }

    Bus::assertDispatchedTimes(ProjectForecastJob::class, count(ForecastHorizon::days()) * 2);
});

// The consequence of a sweep that never ran: the newest run is however old the
// last event-driven re-projection left it, and its curve opens on the day it
// was computed. Drawn under the word "today", a ten-day-old run shows ten days
// already spent as though they were still ahead.

function sweepAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Sweep Bank',
        'slug' => 'sweep-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00SWEEP'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function sweepSeedRun(DatabaseManager $db, int $userId, int $accountId, string $asOf): void
{
    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => ForecastHorizon::OneMonth->value,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => $asOf,
            'horizon_days' => ForecastHorizon::OneMonth->value,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'Sweep Bank',
                    'default_currency' => Currency::Eur->value,
                    'today_balance_minor' => 100_000,
                    'anchor_source' => 'sum_of_transactions',
                    'points' => [[
                        'date' => $asOf,
                        'low_minor' => 100_000,
                        'point_minor' => 100_000,
                        'high_minor' => 100_000,
                        'currency' => Currency::Eur->value,
                    ]],
                ],
            ],
        ]),
        'created_at' => $asOf.' 00:00:00',
        'updated_at' => $asOf.' 00:00:00',
    ]);
}

it('says so on the page when the run it is drawing opens ten days in the past', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $db = app(DatabaseManager::class);
    $accountId = sweepAccount($db, $this->user->id);
    $staleAsOf = CarbonImmutable::now()->subDays(10)->toDateString();
    sweepSeedRun($db, $this->user->id, $accountId, $staleAsOf);

    $forecast = app(ForecastQuery::class)->forUser(
        $accountId,
        ForecastHorizon::OneMonth->value,
        null,
        $this->user,
    );
    $content = (string) $this->actingAs($this->user)->get('/forecast?account='.$accountId)->getContent();

    expect($forecast->isStale)->toBeTrue()
        ->and($content)->toContain('data-testid="forecast-stale-note"')
        ->and($content)->toContain(Lang::get('forecasting::forecast.stale_run', [
            'date' => CarbonImmutable::parse($staleAsOf)->translatedFormat('d M Y'),
        ]));

    CarbonImmutable::setTestNow(null);
});

it('says nothing about staleness on a run computed today', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $db = app(DatabaseManager::class);
    $accountId = sweepAccount($db, $this->user->id);
    sweepSeedRun($db, $this->user->id, $accountId, CarbonImmutable::now()->toDateString());

    $forecast = app(ForecastQuery::class)->forUser(
        $accountId,
        ForecastHorizon::OneMonth->value,
        null,
        $this->user,
    );
    $content = (string) $this->actingAs($this->user)->get('/forecast?account='.$accountId)->getContent();

    expect($forecast->isStale)->toBeFalse()
        ->and($content)->not->toContain('data-testid="forecast-stale-note"');

    CarbonImmutable::setTestNow(null);
});
