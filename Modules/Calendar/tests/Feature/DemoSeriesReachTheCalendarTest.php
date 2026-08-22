<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Enums\RecurringSeriesState;

uses(RefreshDatabase::class);

// A series' calendar placement is floored at its inception — the earliest
// observed occurrence, or created_at when it has none. A demo series seeded
// without occurrence history therefore has today as its inception, and this
// month's already-paid instalment falls off the grid.
// @link ../../../../.docs/features/calendar/architecture.md#entry-placement

/** @return list<string> */
function calendarEntryNamesThisMonth(User $user): array
{
    $today = CarbonImmutable::today();

    /** @var list<CalendarDayDto> $days */
    $days = app(CalendarQuery::class)->forMonth($user, $today->year, $today->month);

    $names = [];
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            $names[] = $entry->name;
        }
    }

    return $names;
}

it('gives every approved demo series its own observed occurrence', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1@beatrax.local')->firstOrFail();
    $this->actingAs($user);

    $seriesIds = DB::table('recurring_series')
        ->where('user_id', $user->id)
        ->where('state', RecurringSeriesState::Approved->value)
        ->pluck('detected_name', 'id');

    expect($seriesIds)->not->toBeEmpty();

    foreach ($seriesIds as $seriesId => $name) {
        $observed = DB::table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->count();

        expect($observed)->toBeGreaterThan(0, "{$name} was seeded with no occurrence history");
    }
});

it('places every approved demo series on this month grid', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1@beatrax.local')->firstOrFail();
    $this->actingAs($user);

    $names = calendarEntryNamesThisMonth($user);

    $approved = DB::table('recurring_series')
        ->where('user_id', $user->id)
        ->where('state', RecurringSeriesState::Approved->value)
        ->pluck('display_name_override');

    $missing = array_values(array_diff($approved->all(), $names));

    expect($missing)->toBe([], 'approved demo series that never reach the calendar grid');
});
