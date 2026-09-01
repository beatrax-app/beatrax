<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Enums\RecurringSeriesState;

uses(RefreshDatabase::class);

// The demo window is the reader's current budget period and the two before it,
// and a subscription's charge was written on its billing day in every one of
// them — including the current period, whose billing day may still be ahead.
// On the first of a month that recorded a payment nobody has made, and the
// series then reported its next charge a month out, off the grid entirely.
//
// Only the first ten-or-so days of a month can show it, which is why it stood
// for as long as it did.

beforeEach(function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $this->demoUser = User::query()->where('username', 'demo-1')->firstOrFail();
});

it('records no subscription charge on a day that has not arrived', function (): void {
    $today = CarbonImmutable::today()->toDateString();

    $ahead = DB::table('recurring_series_occurrences')
        ->where('user_id', $this->demoUser->id)
        ->whereDate('observed_at', '>', $today)
        ->count();

    expect($ahead)->toBe(0, 'occurrences the demo says were observed after today');
});

it('expects a monthly charge within the month, not a month past it', function (): void {
    $today = CarbonImmutable::today();

    $series = DB::table('recurring_series')
        ->where('user_id', $this->demoUser->id)
        ->where('state', RecurringSeriesState::Approved->value)
        ->where('cadence', 'monthly')
        ->get(['display_name_override', 'next_expected_at']);

    // A walk that found nothing would pass while proving nothing.
    expect($series->count())->toBeGreaterThan(0);

    $tooFar = [];

    foreach ($series as $row) {
        $next = CarbonImmutable::parse((string) $row->next_expected_at);

        if ($next->greaterThan($today->addDays(31)) || $next->lessThan($today)) {
            $tooFar[] = $row->display_name_override.' expects its next charge on '.$next->toDateString();
        }
    }

    expect($tooFar)->toBe([], implode("\n  ", [
        'A monthly series cannot be more than a month from its next charge:',
        ...$tooFar,
    ]));
});
