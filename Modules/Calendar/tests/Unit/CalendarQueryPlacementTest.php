<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

function cqplUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cqpl-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cqplSeries(User $user, string $name, CarbonImmutable $nextExpectedAt, string $cadence = 'monthly'): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => $cadence,
        'latest_amount_minor' => -1500,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cqpl::'.$name,
        'next_expected_at' => $nextExpectedAt,
    ]);
}

function cqplOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt): void
{
    static $cqplOccCounter = 0;
    $cqplOccCounter++;

    $hex = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CQPL ASN',
        'slug' => 'cqpl-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00CQPL'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-01-01',
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cqpl-occ-'.$cqplOccCounter.'.csv',
        'sha256' => str_pad((string) ($cqplOccCounter + 500), 64, 'c'),
        'uploaded_at' => $observedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad((string) ($cqplOccCounter + 500), 64, 'd'),
        'fingerprint_version' => 3,
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'cqpl-counterparty',
        'counterparty_name' => 'CQPL Test',
        'normalization_version' => 1,
        'description' => 'cqpl occurrence fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $cqplOccCounter + 500,
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'observed_at' => $observedAt,
        'observed_amount_minor' => -1500,
        'observed_currency' => 'EUR',
        'transaction_id' => $txId,
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);
}

/**
 * @return list<string> entry dates (Y-m-d) for entries matching $name
 */
function cqplEntryDates(array $days, string $name): array
{
    $dates = [];
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === $name) {
                $dates[] = $day->date->toDateString();
            }
        }
    }

    return $dates;
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('places no entries in history months before the series existed (WR-03)', function (): void {
    $user = cqplUser('inception-history');

    // Created "today", with no occurrences at all.
    cqplSeries($user, 'New-Subscription', CarbonImmutable::parse('2026-06-15'));

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    // Years before the subscription existed.
    $days = $calendarQuery->forMonth($user, 2024, 3);

    expect(cqplEntryDates($days, 'New-Subscription'))->toBe([]);
});

it('uses the first observed occurrence as the inception floor (WR-03)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqplUser('inception-occurrence');

    $series = cqplSeries($user, 'April-Born', CarbonImmutable::parse('2026-07-15'));
    cqplOccurrence($db, $user->id, $series->id, '2026-04-15');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $april = $calendarQuery->forMonth($user, 2026, 4);
    expect(cqplEntryDates($april, 'April-Born'))->toBe(['2026-04-15']);

    $february = $calendarQuery->forMonth($user, 2026, 2);
    expect(cqplEntryDates($february, 'April-Born'))->toBe([]);
});

it('preserves an end-of-month anchor across short months (WR-04 drift)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqplUser('eom-anchor');

    // Observed since January, so history months clear the inception floor.
    $series = cqplSeries($user, 'EndOfMonth-Bill', CarbonImmutable::parse('2026-07-31'));
    cqplOccurrence($db, $user->id, $series->id, '2026-01-31');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    // February has no 31st → no-overflow clamps to Feb 28…
    $february = $calendarQuery->forMonth($user, 2026, 2);
    expect(cqplEntryDates($february, 'EndOfMonth-Bill'))->toBe(['2026-02-28']);

    // …but March must return to the 31st; chained stepping drifted to Mar 28
    // and never recovered.
    $march = $calendarQuery->forMonth($user, 2026, 3);
    expect(cqplEntryDates($march, 'EndOfMonth-Bill'))->toBe(['2026-03-31']);

    $may = $calendarQuery->forMonth($user, 2026, 5);
    expect(cqplEntryDates($may, 'EndOfMonth-Bill'))->toBe(['2026-05-31']);
});

it('places the anchor month itself on the anchor day (WR-04 invertibility)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqplUser('eom-invert');

    // Stepping through short months must not move the anchor's own month.
    $series = cqplSeries($user, 'July-Anchor', CarbonImmutable::parse('2026-07-31'));
    cqplOccurrence($db, $user->id, $series->id, '2026-01-31');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $july = $calendarQuery->forMonth($user, 2026, 7);
    expect(cqplEntryDates($july, 'July-Anchor'))->toBe(['2026-07-31']);
});

it('steps a quarterly series by three-month index from the anchor (WR-04)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqplUser('quarterly-step');

    // Observed since January, so the floor admits the backward steps.
    $series = cqplSeries($user, 'Quarterly-Bill', CarbonImmutable::parse('2026-07-15'), 'quarterly');
    cqplOccurrence($db, $user->id, $series->id, '2026-01-15');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    expect(cqplEntryDates($calendarQuery->forMonth($user, 2026, 4), 'Quarterly-Bill'))->toBe(['2026-04-15']);
    expect(cqplEntryDates($calendarQuery->forMonth($user, 2026, 5), 'Quarterly-Bill'))->toBe([]);
    expect(cqplEntryDates($calendarQuery->forMonth($user, 2026, 7), 'Quarterly-Bill'))->toBe(['2026-07-15']);
});

it('steps a yearly series by one-year index from the anchor (WR-04)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqplUser('yearly-step');

    // Observed since 2024, so a prior year is inside the floor.
    $series = cqplSeries($user, 'Yearly-Bill', CarbonImmutable::parse('2026-07-15'), 'yearly');
    cqplOccurrence($db, $user->id, $series->id, '2024-07-15');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    expect(cqplEntryDates($calendarQuery->forMonth($user, 2025, 7), 'Yearly-Bill'))->toBe(['2025-07-15']);
    expect(cqplEntryDates($calendarQuery->forMonth($user, 2025, 8), 'Yearly-Bill'))->toBe([]);
    expect(cqplEntryDates($calendarQuery->forMonth($user, 2026, 7), 'Yearly-Bill'))->toBe(['2026-07-15']);
});

it('keeps an entry expected slightly before its first observed payment (WR-03 slack)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqplUser('inception-slack');

    // Expected June 1 (monthly anchor), first paid June 3 — the floor slack
    // must not drop the very entry the occurrence pays.
    $series = cqplSeries($user, 'Paid-Late', CarbonImmutable::parse('2026-07-01'));
    cqplOccurrence($db, $user->id, $series->id, '2026-06-03');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6);

    expect(cqplEntryDates($days, 'Paid-Late'))->toBe(['2026-06-01']);
});
