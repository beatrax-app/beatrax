<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Models\RecurringSeries;

uses(RefreshDatabase::class);

// The backward walk is ceilinged at today because a step behind the anchor that
// lands on a day still to come is an entry no balance line accounts for. Today
// is not still to come, and `gte` dropped it: a series whose billing day IS
// today's has this period's instalment one step behind an anchor in the next
// one. It shows on that single day of the month, which is why the demo seed
// caught it on the eleventh and on no other date.

const TGD_TODAY = '2026-09-11';

function tgdFreezeClock(string $date): void
{
    $frozen = CarbonImmutable::parse($date.' 09:00:00');

    app()->instance(Clock::class, new class($frozen) implements Clock
    {
        public function __construct(private CarbonImmutable $at) {}

        public function now(): CarbonImmutable
        {
            return $this->at;
        }
    });
}

function tgdUser(): User
{
    return User::query()->create([
        'username' => 'tgd-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function tgdSeries(User $user, string $name, string $nextExpectedAt): RecurringSeries
{
    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'display_name_override' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'tgd::'.$name,
        'next_expected_at' => $nextExpectedAt,
    ]);

    return $series;
}

/** @return list<string> */
function tgdNamesOn(User $user, string $date): array
{
    $on = CarbonImmutable::parse($date);

    /** @var list<CalendarDayDto> $days */
    $days = app(CalendarQuery::class)->forMonth($user, $on->year, $on->month);

    $names = [];

    foreach ($days as $day) {
        if ($day->date->toDateString() !== $date) {
            continue;
        }

        foreach ($day->entries as $entry) {
            $names[] = $entry->name;
        }
    }

    return $names;
}

it('places this period instalment on today when today is the billing day', function (): void {
    tgdFreezeClock(TGD_TODAY);

    $user = tgdUser();
    test()->actingAs($user);

    // Anchored one period ahead, which is what the app answers once this
    // month's charge has been taken.
    tgdSeries($user, 'Streaming Premium', '2026-10-11');

    expect(tgdNamesOn($user, TGD_TODAY))->toContain('Streaming Premium');
});

// The positive control. The ceiling is still a ceiling: a step behind the anchor
// landing tomorrow is an entry the forecast never emits, and it stays dropped.
it('still drops a step behind the anchor that lands after today', function (): void {
    tgdFreezeClock(TGD_TODAY);

    $user = tgdUser();
    test()->actingAs($user);

    tgdSeries($user, 'Gym Membership', '2026-10-12');

    expect(tgdNamesOn($user, '2026-09-12'))->not->toContain('Gym Membership');
});

// And the anchor itself is placed whatever the ceiling says, because it is not
// a step behind anything: without this, a guard that dropped every future entry
// would satisfy the case above.
it('places the anchor on its own day', function (): void {
    tgdFreezeClock(TGD_TODAY);

    $user = tgdUser();
    test()->actingAs($user);

    tgdSeries($user, 'Insurance', '2026-09-25');

    expect(tgdNamesOn($user, '2026-09-25'))->toContain('Insurance');
});
