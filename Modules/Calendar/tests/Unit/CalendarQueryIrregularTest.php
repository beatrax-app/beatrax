<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

/*
 * CalendarQuery — irregular-cadence series placement (CAL-01 edge).
 *
 * RED state (Phase 6 Plan 01): CalendarQuery does not exist yet;
 * it arrives in Plan 02. These tests establish the contract that
 * CalendarQuery must honour:
 *
 *   1. An irregular-cadence series with nextExpectedAt=null is excluded
 *      from all calendar dates — no entry is placed.
 *   2. An irregular-cadence series with nextExpectedAt inside the
 *      display window appears exactly once on that date.
 *   3. An irregular-cadence series with nextExpectedAt outside the
 *      display window does not appear.
 *
 * The gate (Pitfall 4 in RESEARCH.md): CalendarQuery must gate on
 * `$series->cadence !== 'irregular' || $series->nextExpectedAt !== null`
 * to avoid null-pointer errors and phantom date placements.
 *
 * Fixtures: cross-user isolation — every query scopes to user_id.
 */

function cqirUser(string $suffix = 'cqir'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cqirSeries(User $user, string $cadence, ?CarbonImmutable $nextExpectedAt, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => $cadence,
        'latest_amount_minor' => -5000,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cqir::'.$name,
        'next_expected_at' => $nextExpectedAt,
    ]);
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('excludes an irregular-cadence series with null nextExpectedAt from the calendar', function (): void {
    $user = cqirUser('cqir-null');
    cqirSeries($user, 'irregular', null, 'irregular-no-date');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($user, 2026, 6);

    $hasIrregularEntry = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'irregular-no-date') {
                $hasIrregularEntry = true;
            }
        }
    }

    expect($hasIrregularEntry)->toBeFalse(
        'An irregular series with null nextExpectedAt must not appear on any calendar day'
    );
});

it('places an irregular-cadence series with nextExpectedAt inside the window exactly once', function (): void {
    $user = cqirUser('cqir-in-window');
    cqirSeries($user, 'irregular', CarbonImmutable::parse('2026-06-20'), 'irregular-in-window');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($user, 2026, 6);

    $matchCount = 0;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'irregular-in-window') {
                $matchCount++;
            }
        }
    }

    expect($matchCount)->toBe(1, 'An irregular series with nextExpectedAt in the window appears exactly once');
});

it('excludes an irregular-cadence series with nextExpectedAt outside the display window', function (): void {
    $user = cqirUser('cqir-out-window');
    // nextExpectedAt is in July — outside the June display window
    cqirSeries($user, 'irregular', CarbonImmutable::parse('2026-07-15'), 'irregular-out-of-window');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($user, 2026, 6);

    $hasEntry = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'irregular-out-of-window') {
                $hasEntry = true;
            }
        }
    }

    expect($hasEntry)->toBeFalse(
        'An irregular series with nextExpectedAt outside the window must not appear'
    );
});

it('does not leak irregular-cadence entries across users', function (): void {
    $owner = cqirUser('cqir-owner');
    $other = cqirUser('cqir-other');

    // Other user's irregular series falls in the window
    cqirSeries($other, 'irregular', CarbonImmutable::parse('2026-06-20'), 'other-irregular');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($owner, 2026, 6);

    $hasOtherEntry = false;
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            if ($entry->name === 'other-irregular') {
                $hasOtherEntry = true;
            }
        }
    }

    expect($hasOtherEntry)->toBeFalse('Cross-user series must never appear on another user\'s calendar');
});
