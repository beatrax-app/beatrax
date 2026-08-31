<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;

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

it('chains today\'s SoD from yesterday\'s actual and marks unknown SoD as null', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqsbUser('anchor');
    $accountId = cqsbAccount($db, $user->id);

    cqsbTransaction($db, $user->id, $accountId, '2026-06-10', 50000);

    // Three points only — the gaps between them are what this exercises. The
    // anchor matches the real balance, as it does in production.
    cqsbForecastRun($db, $user->id, $accountId, 50000, [
        '2026-06-12' => 48000,
        '2026-06-19' => 30000,
        '2026-06-20' => 25000,
    ]);

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6, null, [$accountId]);

    // Past days carry actuals: a known balance, no computing sentinel.
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

    expect(cqsbDay($days, '2026-06-13')->sodBalanceMinor)->toBe(48000);

    // June 13 had no point, so June 14's SoD must be null, not a fabricated 0.
    expect(cqsbDay($days, '2026-06-14')->sodBalanceMinor)->toBeNull();

    expect(cqsbDay($days, '2026-06-20')->sodBalanceMinor)->toBe(30000);

    // June 2026 starts on a Monday, so June 1 is the first grid day. It has no
    // prior grid day to chain from, but the aggregator already computed the
    // balance the day opened on — that figure seeds the actuals overlay — so
    // the panel states it rather than reporting a value it holds as unknown.
    $gridFirst = cqsbDay($days, '2026-06-01');
    expect($gridFirst->sodBalanceMinor)->toBe(0);
    expect($gridFirst->isComputing)->toBeFalse();
    expect($gridFirst->eodBalanceMinor)->toBe(0);
});

// The opening figure exists only where the actuals overlay reaches. A grid that
// starts after today is projection all the way down, and a projection carries
// points for its own days and no opening balance for the day before the first.
it('still reports an unknown start of day on a grid the actuals overlay never reaches', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqsbUser('future-grid');
    $accountId = cqsbAccount($db, $user->id);

    cqsbTransaction($db, $user->id, $accountId, '2026-06-10', 50000);
    cqsbForecastRun($db, $user->id, $accountId, 50000, ['2026-09-05' => 40000]);

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 9, null, [$accountId]);

    expect($days[0]->date->toDateString())->toBe('2026-08-31')
        ->and($days[0]->sodBalanceMinor)->toBeNull();
});

// A grid start the overlay does reach but cannot price is not a known opening
// either: the figure it would state is the priced part of a partial line.
it('keeps the first grid day unknown when its opening balance could not be priced', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqsbUser('unpriced-open');

    $hex = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'CQSB Peso',
        'slug' => 'cqsb-ars-'.$hex,
        'kind' => 'bank',
        'iban' => 'AR00CQSB'.strtoupper($hex),
        'default_currency' => 'ARS',
        'opening_balance_minor' => 1_000_000,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6, null, [$accountId]);

    expect(cqsbDay($days, '2026-06-01')->sodBalanceMinor)->toBeNull();
});
