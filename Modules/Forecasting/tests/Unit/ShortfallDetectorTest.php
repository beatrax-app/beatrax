<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Pipeline\ShortfallDetector;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

function sdUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function sdAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'sd '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'SD-'.strtoupper($slug),
        'default_currency' => 'EUR',
    ]);
}

function sdDetector(?Clock $clock = null): ShortfallDetector
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $clock ??= new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-19 00:00:00');
        }
    };

    return new ShortfallDetector($db, $events, $clock);
}

/**
 * @return list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>
 */
function sdPoints(array $balanceByDate): array
{
    $points = [];
    foreach ($balanceByDate as $date => $balance) {
        $points[] = [
            'date' => $date,
            'low_minor' => $balance,
            'point_minor' => $balance,
            'high_minor' => $balance,
            'currency' => 'EUR',
        ];
    }

    return $points;
}

beforeEach(function (): void {
    $this->user = sdUser('shortfall');
    $this->account = sdAccount($this->user, 'bank');
});

it('emits a window with buffer_used_minor=0 on the zero-crossing floor when the balance dips below zero', function (): void {
    $points = sdPoints([
        '2026-05-19' => 100,
        '2026-05-20' => -50,
        '2026-05-21' => -100,
        '2026-05-22' => 50,
    ]);

    $windows = sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    expect($windows)->toHaveCount(1);
    expect($windows[0]['buffer_used_minor'])->toBe(0);
    expect($windows[0]['lowest_balance_minor'])->toBe(-100);
    expect($windows[0]['starts_at'])->toBe('2026-05-20');
    expect($windows[0]['ends_at'])->toBe('2026-05-21');

    $this->assertDatabaseHas('forecast_shortfall_windows', [
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'buffer_used_minor' => 0,
        'lowest_balance_minor' => -100,
    ]);
});

it('emits a window with buffer_used_minor=effective when balance dips below set buffer', function (): void {
    $points = sdPoints([
        '2026-05-19' => 60000,
        '2026-05-20' => 40000,
        '2026-05-21' => 12000,
        '2026-05-22' => 25000,
        '2026-05-23' => 51000,
    ]);

    $windows = sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 50000,
        currency: 'EUR',
        user: $this->user,
    );

    expect($windows)->toHaveCount(1);
    expect($windows[0]['buffer_used_minor'])->toBe(50000);
    expect($windows[0]['lowest_balance_minor'])->toBe(12000);
    expect($windows[0]['starts_at'])->toBe('2026-05-20');
    expect($windows[0]['ends_at'])->toBe('2026-05-22');
});

it('emits two distinct windows when the balance dips, recovers, and dips again', function (): void {
    $points = sdPoints([
        '2026-05-19' => 100,
        '2026-05-20' => -10,
        '2026-05-21' => -5,
        '2026-05-22' => 50,
        '2026-05-23' => 80,
        '2026-05-24' => -20,
        '2026-05-25' => -30,
        '2026-05-26' => 10,
    ]);

    $windows = sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    expect($windows)->toHaveCount(2);
    expect($windows[0]['starts_at'])->toBe('2026-05-20');
    expect($windows[0]['ends_at'])->toBe('2026-05-21');
    expect($windows[0]['lowest_balance_minor'])->toBe(-10);
    expect($windows[1]['starts_at'])->toBe('2026-05-24');
    expect($windows[1]['ends_at'])->toBe('2026-05-25');
    expect($windows[1]['lowest_balance_minor'])->toBe(-30);
});

it('emits a final window with ends_at=last day when in-shortfall at end of horizon', function (): void {
    $points = sdPoints([
        '2026-05-19' => 100,
        '2026-05-20' => -50,
        '2026-05-21' => -75,
    ]);

    $windows = sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    expect($windows)->toHaveCount(1);
    expect($windows[0]['starts_at'])->toBe('2026-05-20');
    expect($windows[0]['ends_at'])->toBe('2026-05-21');
    expect($windows[0]['lowest_balance_minor'])->toBe(-75);
});

it('deletes prior rows for (user, account, scenario, horizon) before inserting new windows (pre-write cleanup)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'starts_at' => '2026-01-01',
        'ends_at' => '2026-01-05',
        'lowest_balance_minor' => -9999,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    expect($db->connection()->table('forecast_shortfall_windows')
        ->where('user_id', $this->user->id)
        ->count())->toBe(1);

    $points = sdPoints([
        '2026-05-19' => 100,
        '2026-05-20' => -50,
        '2026-05-21' => 50,
    ]);

    sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    $rows = $db->connection()->table('forecast_shortfall_windows')
        ->where('user_id', $this->user->id)
        ->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->lowest_balance_minor)->toBe(-50);
});

it('dispatches ForecastShortfallDetected once per new window', function (): void {
    Event::fake([ForecastShortfallDetected::class]);

    $points = sdPoints([
        '2026-05-19' => 100,
        '2026-05-20' => -50,
        '2026-05-21' => 50,
        '2026-05-22' => -20,
        '2026-05-23' => 10,
    ]);

    sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    Event::assertDispatchedTimes(ForecastShortfallDetected::class, 2);
});

it('writes rows scoped to the passed user — never another user (cross-user safety)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $other = sdUser('other-shortfall');
    $otherAccount = sdAccount($other, 'other-asn');

    // User A's row must survive user B's run: the pre-write cleanup is scoped.
    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'scenario_id' => null,
        'horizon_days' => 30,
        'starts_at' => '2026-01-01',
        'ends_at' => '2026-01-05',
        'lowest_balance_minor' => -9999,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $points = sdPoints([
        '2026-05-19' => 100,
        '2026-05-20' => -50,
        '2026-05-21' => 50,
    ]);

    sdDetector()->detect(
        dailyPoints: $points,
        accountId: $otherAccount->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $other,
    );

    expect($db->connection()->table('forecast_shortfall_windows')
        ->where('user_id', $this->user->id)
        ->count())->toBe(1);
    expect($db->connection()->table('forecast_shortfall_windows')
        ->where('user_id', $other->id)
        ->count())->toBe(1);
});

it('leaves the other four horizons\' windows alone when one horizon re-runs', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    foreach (ForecastHorizon::days() as $horizon) {
        sdDetector()->detect(
            dailyPoints: sdPoints([
                '2026-05-19' => 100,
                '2026-05-20' => -50,
                '2026-05-21' => 50,
            ]),
            accountId: $this->account->id,
            scenarioId: null,
            horizonDays: $horizon,
            effectiveBufferMinor: 0,
            currency: 'EUR',
            user: $this->user,
        );
    }

    // Five queued horizons run in whatever order the worker picks them up. An
    // unscoped delete meant the last one to finish spoke for all five, and the
    // 30-day chart shaded a dip only the 365-day run had found.
    expect($db->connection()->table('forecast_shortfall_windows')
        ->where('user_id', $this->user->id)
        ->count())->toBe(count(ForecastHorizon::days()));

    foreach (ForecastHorizon::days() as $horizon) {
        expect($db->connection()->table('forecast_shortfall_windows')
            ->where('user_id', $this->user->id)
            ->where('horizon_days', $horizon)
            ->count())->toBe(1);
    }
});

it('raises no shortfall event for a what-if scenario run', function (): void {
    Event::fake([ForecastShortfallDetected::class]);

    $scenarioId = app(DatabaseManager::class)->connection()->table('forecast_scenarios')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'What if',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $windows = sdDetector()->detect(
        dailyPoints: sdPoints([
            '2026-05-19' => 100,
            '2026-05-20' => -50,
            '2026-05-21' => 50,
        ]),
        accountId: $this->account->id,
        scenarioId: $scenarioId,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    // The window itself is the scenario's own output and stays keyed by its
    // scenario_id; what must not happen is the event that reaches the inbox.
    expect($windows)->toHaveCount(1);
    Event::assertNotDispatched(ForecastShortfallDetected::class);
});

it('hands the notification dedup key a start date no listener can move', function (): void {
    // PersistForecastShortfall builds the occurrence key from startsAt, so a
    // listener that moved the date in place would change which row gets
    // written — and the event is readonly only down to the reference.
    $captured = null;
    Event::listen(
        ForecastShortfallDetected::class,
        static function (ForecastShortfallDetected $event) use (&$captured): void {
            $captured = $event;
        },
    );

    sdDetector()->detect(
        dailyPoints: sdPoints(['2026-05-19' => 100, '2026-05-20' => -50, '2026-05-21' => 50]),
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    expect($captured)->not->toBeNull();

    $before = $captured->startsAt->toDateString();
    $captured->startsAt->addDays(10);
    $captured->endsAt->addDays(10);

    expect($captured->startsAt->toDateString())->toBe($before)
        ->and($before)->toBe('2026-05-20');
});

// Null is not the zero-crossing default: it is "no floor is in force", which is
// what a liability's balance needs. The rows still have to be cleared, or a
// floor the reader has just taken away leaves its last run's windows standing.
it('writes no window and clears the previous ones when no floor is in force', function (): void {
    $points = sdPoints([
        '2026-05-19' => -100,
        '2026-05-20' => -200,
    ]);

    sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    $this->assertDatabaseCount('forecast_shortfall_windows', 1);

    $windows = sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
        horizonDays: 30,
        effectiveBufferMinor: null,
        currency: 'EUR',
        user: $this->user,
    );

    expect($windows)->toBe([]);
    $this->assertDatabaseCount('forecast_shortfall_windows', 0);
});
