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
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

/*
 * Unit coverage for ShortfallDetector.
 *
 * Covers the state-machine traversal of daily points and the
 * pre-write cleanup contract:
 *   - No buffer (NULL) — zero-crossing default.
 *   - Buffer set + dip below — single window emitted.
 *   - Two separate dips — two windows emitted.
 *   - End-of-horizon in-shortfall — final window emitted.
 *   - Pre-write cleanup deletes prior rows before inserting new ones.
 *   - ForecastShortfallDetected event dispatched per new window.
 *   - Cross-user safety — writes use the passed User->id.
 */

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

it('emits a window with buffer_used_minor=0 when no buffer is set and balance dips below zero', function (): void {
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
        effectiveBufferMinor: null,
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
        '2026-05-20' => -10, // shortfall 1 starts
        '2026-05-21' => -5,
        '2026-05-22' => 50, // recovery
        '2026-05-23' => 80,
        '2026-05-24' => -20, // shortfall 2 starts
        '2026-05-25' => -30,
        '2026-05-26' => 10, // recovery
    ]);

    $windows = sdDetector()->detect(
        dailyPoints: $points,
        accountId: $this->account->id,
        scenarioId: null,
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
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    expect($windows)->toHaveCount(1);
    expect($windows[0]['starts_at'])->toBe('2026-05-20');
    expect($windows[0]['ends_at'])->toBe('2026-05-21');
    expect($windows[0]['lowest_balance_minor'])->toBe(-75);
});

it('deletes prior rows for (user, account, scenario) before inserting new windows (pre-write cleanup)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Seed a stale window manually.
    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'scenario_id' => null,
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
        effectiveBufferMinor: 0,
        currency: 'EUR',
        user: $this->user,
    );

    // The stale row is gone; only the new window survives.
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

    // Seed a stale row on user A.
    $db->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'scenario_id' => null,
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

    // Run for user B — must not delete user A's row.
    sdDetector()->detect(
        dailyPoints: $points,
        accountId: $otherAccount->id,
        scenarioId: null,
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
