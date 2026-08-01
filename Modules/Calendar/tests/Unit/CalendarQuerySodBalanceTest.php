<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Calendar\Public\Dto\CalendarDayDto;
use Modules\Core\Models\User;

/*
 * CalendarQuery — start-of-day balance chain honesty (WR-08) and past-day
 * actual balances (WR-09, D-07).
 *
 * Contract:
 *   - Past days carry the REAL cumulative transaction balance (actuals),
 *     independent of forecast runs (D-07: "real balances up to today and
 *     projection after").
 *   - SoD is the prior grid day's EoD ONLY when that EoD is known
 *     (non-computing). A day after a data-less day carries
 *     sodBalanceMinor === null ("unknown"), never a fabricated 0.
 *   - Today's SoD chains from yesterday's actual EoD; the forecast's
 *     todayBalanceMinor anchor is the fallback when no actual exists.
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
        'kind' => 'bank',
        'iban' => 'NL00CQSB'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function cqsbTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $amountMinor): void
{
    static $cqsbTxCounter = 0;
    $cqsbTxCounter++;

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cqsb-'.$cqsbTxCounter.'.csv',
        'sha256' => str_pad((string) ($cqsbTxCounter + 900), 64, 'e'),
        'uploaded_at' => $postedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad((string) ($cqsbTxCounter + 900), 64, 'f'),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'cqsb-counterparty',
        'counterparty_name' => 'CQSB Test',
        'normalization_version' => 1,
        'description' => 'cqsb balance fixture',
        'type' => $amountMinor >= 0 ? 'income' : 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $cqsbTxCounter + 900,
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
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

it('chains today\'s SoD from yesterday\'s actual and marks unknown SoD as null (WR-08, WR-09)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqsbUser('anchor');
    $accountId = cqsbAccount($db, $user->id);

    // Real balance: one +€500,00 income on June 10 → actual EoD 50000 from
    // June 10 onward (past days are actuals per D-07 / WR-09).
    cqsbTransaction($db, $user->id, $accountId, '2026-06-10', 50000);

    // Forecast: points on today (June 12), June 19 and June 20 only — gaps
    // elsewhere. The anchor agrees with the real balance (as in production,
    // where the anchor IS the transactions sum).
    cqsbForecastRun($db, $user->id, $accountId, 50000, [
        '2026-06-12' => 48000,
        '2026-06-19' => 30000,
        '2026-06-20' => 25000,
    ]);

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6, null, [$accountId]);

    // Past days carry actuals (WR-09): known balance, no computing sentinel.
    $june10 = cqsbDay($days, '2026-06-10');
    expect($june10->isComputing)->toBeFalse();
    expect($june10->eodBalanceMinor)->toBe(50000);

    // June 11 (no transactions): the cumulative balance carries forward.
    $june11 = cqsbDay($days, '2026-06-11');
    expect($june11->isComputing)->toBeFalse();
    expect($june11->eodBalanceMinor)->toBe(50000);
    expect($june11->sodBalanceMinor)->toBe(50000);

    // Today: SoD chains from yesterday's ACTUAL EoD; EoD from today's point.
    $today = cqsbDay($days, '2026-06-12');
    expect($today->isComputing)->toBeFalse();
    expect($today->sodBalanceMinor)->toBe(50000);
    expect($today->eodBalanceMinor)->toBe(48000);

    // June 13: prior day's EoD is known → SoD chains from it.
    expect(cqsbDay($days, '2026-06-13')->sodBalanceMinor)->toBe(48000);

    // June 14: prior day (13) had no point → unknown EoD → SoD must be null,
    // not a fabricated 0 (WR-08).
    expect(cqsbDay($days, '2026-06-14')->sodBalanceMinor)->toBeNull();

    // June 20: prior day (19) is known again → SoD chains.
    expect(cqsbDay($days, '2026-06-20')->sodBalanceMinor)->toBe(30000);

    // The first grid day (Mon June 1 — June 2026 starts on a Monday) has no
    // prior day inside the grid → SoD unknown (WR-08), while its own EoD is
    // a real actual (€0,00 — no transactions existed yet).
    $gridFirst = cqsbDay($days, '2026-06-01');
    expect($gridFirst->sodBalanceMinor)->toBeNull();
    expect($gridFirst->isComputing)->toBeFalse();
    expect($gridFirst->eodBalanceMinor)->toBe(0);
});
