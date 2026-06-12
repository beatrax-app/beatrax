<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Calendar\Public\Dto\CalendarDayDto;
use Modules\Core\Models\User;

/*
 * CalendarQuery — start-of-day balance chain honesty (WR-08).
 *
 * Contract:
 *   - SoD is the prior grid day's EoD ONLY when that EoD is known
 *     (non-computing). A day after a data-less day carries
 *     sodBalanceMinor === null ("unknown"), never a fabricated 0.
 *   - Today's SoD is seeded from the forecast's todayBalanceMinor anchor
 *     (yesterday is a past day with no forecast point, so the chain alone
 *     cannot know it).
 */

function cqsbUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cqsb-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cqsbAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CQSB ASN',
        'slug' => 'cqsb-'.$hex,
        'kind' => 'asn',
        'iban' => 'NL00CQSB'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

/**
 * Seed a complete 365-day forecast_run with explicit points.
 *
 * @param  array<string, int>  $pointsByDate  date => pointMinor
 */
function cqsbForecastRun(DatabaseManager $db, int $userId, int $accountId, int $todayBalanceMinor, array $pointsByDate): void
{
    $points = [];
    foreach ($pointsByDate as $date => $pointMinor) {
        $points[] = [
            'date' => $date,
            'low_minor' => $pointMinor,
            'point_minor' => $pointMinor,
            'high_minor' => $pointMinor,
            'currency' => 'EUR',
        ];
    }

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => 365,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => '2026-06-12',
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'CQSB ASN',
                    'default_currency' => 'EUR',
                    'today_balance_minor' => $todayBalanceMinor,
                    'points' => $points,
                ],
            ],
        ]),
        'created_at' => '2026-06-12 00:00:00',
        'updated_at' => '2026-06-12 00:00:00',
    ]);
}

/**
 * @param  list<CalendarDayDto>  $days
 */
function cqsbDay(array $days, string $date): CalendarDayDto
{
    foreach ($days as $day) {
        if ($day->date->toDateString() === $date) {
            return $day;
        }
    }

    throw new RuntimeException('Grid day not found: '.$date);
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('seeds today\'s SoD from the forecast anchor and chains only known EoDs (WR-08)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqsbUser('anchor');
    $accountId = cqsbAccount($db, $user->id);

    // Points on today (June 12), June 19 and June 20 only — gaps elsewhere.
    cqsbForecastRun($db, $user->id, $accountId, 50000, [
        '2026-06-12' => 48000,
        '2026-06-19' => 30000,
        '2026-06-20' => 25000,
    ]);

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6, null, [$accountId]);

    // Today: SoD comes from todayBalanceMinor, EoD from today's point.
    $today = cqsbDay($days, '2026-06-12');
    expect($today->isComputing)->toBeFalse();
    expect($today->sodBalanceMinor)->toBe(50000);
    expect($today->eodBalanceMinor)->toBe(48000);

    // June 13: prior day's EoD is known → SoD chains from it.
    expect(cqsbDay($days, '2026-06-13')->sodBalanceMinor)->toBe(48000);

    // June 14: prior day (13) had no point → unknown EoD → SoD must be null,
    // not a fabricated 0.
    expect(cqsbDay($days, '2026-06-14')->sodBalanceMinor)->toBeNull();

    // June 20: prior day (19) is known again → SoD chains.
    expect(cqsbDay($days, '2026-06-20')->sodBalanceMinor)->toBe(30000);

    // A past day (June 11) has no balance data at all → SoD unknown.
    expect(cqsbDay($days, '2026-06-11')->sodBalanceMinor)->toBeNull();
});
