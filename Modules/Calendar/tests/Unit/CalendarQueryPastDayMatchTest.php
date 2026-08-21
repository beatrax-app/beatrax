<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Calendar\Internal\Services\OccurrenceMatcher;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

function cqpdUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cqpd-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cqpdSeries(User $user, string $name, CarbonImmutable $nextExpectedAt, string $cadence = 'monthly'): RecurringSeries
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
        'cluster_key' => 'cqpd::'.$name,
        'next_expected_at' => $nextExpectedAt,
    ]);
}

function cqpdOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt): void
{
    static $cqpdOccCounter = 0;
    $cqpdOccCounter++;

    // A fresh account and run every call: RefreshDatabase rolls back between
    // tests, so a cached id from a prior test would point at a deleted row.
    $hex = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CQPD ASN',
        'slug' => 'cqpd-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00CQPD'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-01-01',
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cqpd-occ-'.$cqpdOccCounter.'.csv',
        'sha256' => str_pad((string) $cqpdOccCounter, 64, 'f'),
        'uploaded_at' => $observedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad((string) $cqpdOccCounter, 64, 'a'),
        'fingerprint_version' => 3,
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'cqpd-counterparty',
        'counterparty_name' => 'CQPD Test',
        'normalization_version' => 1,
        'description' => 'cqpd occurrence fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $cqpdOccCounter,
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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // "Today" is June 12 so June 1–11 are past days
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('marks a past-day entry isPaid when an occurrence is within ±7 days', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqpdUser('paid-match');

    $series = cqpdSeries($user, 'Paid-OnTime', CarbonImmutable::parse('2026-06-05'));
    cqpdOccurrence($db, $user->id, $series->id, '2026-06-05');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6);

    $foundPaid = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'Paid-OnTime' && $entry->isPaid) {
                $foundPaid = true;
            }
        }
    }

    expect($foundPaid)->toBeTrue('Occurrence within ±7 days should mark the entry isPaid');
});

it('marks a past-day entry isPaid when occurrence is within the window (not exact date)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqpdUser('paid-near');

    $series = cqpdSeries($user, 'Paid-Early', CarbonImmutable::parse('2026-06-05'));
    cqpdOccurrence($db, $user->id, $series->id, '2026-06-03');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6);

    $foundPaid = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'Paid-Early' && $entry->isPaid) {
                $foundPaid = true;
            }
        }
    }

    expect($foundPaid)->toBeTrue('Occurrence 2 days early is within ±7 days → isPaid');
});

it('marks a past-day entry isMissed when no occurrence exists for an expected date', function (): void {
    $user = cqpdUser('missed');

    cqpdSeries($user, 'Missed-Payment', CarbonImmutable::parse('2026-06-08'));

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6);

    $foundMissed = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'Missed-Payment' && $entry->isMissed) {
                $foundMissed = true;
            }
        }
    }

    expect($foundMissed)->toBeTrue('Expected-but-absent past-day entry should be isMissed');
});

it('marks a past-day entry isMissed when the occurrence is 10 days off (outside window)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqpdUser('missed-far');

    $series = cqpdSeries($user, 'Missed-Far', CarbonImmutable::parse('2026-06-05'));
    cqpdOccurrence($db, $user->id, $series->id, '2026-05-26'); // 10 days early — outside ±7

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6);

    $foundMissed = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'Missed-Far' && $entry->isMissed) {
                $foundMissed = true;
            }
        }
    }

    expect($foundMissed)->toBeTrue('Occurrence 10 days off (outside ±7) → still isMissed');
});

it('does not mark a future-day entry as paid or missed', function (): void {
    $user = cqpdUser('future');

    cqpdSeries($user, 'Future-Payment', CarbonImmutable::parse('2026-06-20'));

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6);

    $foundFuture = false;
    $foundPaidOrMissed = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'Future-Payment') {
                $foundFuture = true;
                if ($entry->isPaid || $entry->isMissed) {
                    $foundPaidOrMissed = true;
                }
            }
        }
    }

    expect($foundFuture)->toBeTrue('Future series entry should appear on the grid');
    expect($foundPaidOrMissed)->toBeFalse('Future entries must not be marked isPaid or isMissed');
});

it('clamps the weekly match window to ±3 days so one payment marks only the nearest entry paid (WR-02)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqpdUser('weekly-clamp');

    // A weekly anchor of June 15 places entries on June 1, 8 and 15. The one
    // occurrence on June 8 must pay only June 8: June 1 is 7 days away,
    // outside the clamped ±3-day weekly window.
    $series = cqpdSeries($user, 'Weekly-Clamp', CarbonImmutable::parse('2026-06-15'), 'weekly');
    cqpdOccurrence($db, $user->id, $series->id, '2026-06-08');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);
    $days = $calendarQuery->forMonth($user, 2026, 6);

    $stateByDate = [];
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'Weekly-Clamp') {
                $stateByDate[$day->date->toDateString()] = ['paid' => $entry->isPaid, 'missed' => $entry->isMissed];
            }
        }
    }

    expect($stateByDate['2026-06-08']['paid'] ?? null)->toBeTrue('The June 8 occurrence pays the June 8 entry');
    expect($stateByDate['2026-06-01']['paid'] ?? null)->toBeFalse('June 1 is 7 days from the occurrence — outside the ±3 weekly window');
    expect($stateByDate['2026-06-01']['missed'] ?? null)->toBeTrue('The unpaid June 1 weekly entry must read missed');
});

it('verifies MATCH_WINDOW_DAYS constant equals 7', function (): void {
    $window = (new ReflectionClass(OccurrenceMatcher::class))
        ->getConstant('MATCH_WINDOW_DAYS');

    expect($window)->toBe(7);
});
