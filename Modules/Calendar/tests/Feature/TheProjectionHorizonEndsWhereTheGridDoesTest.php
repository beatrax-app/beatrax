<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\Internal\Services\CalendarGrid;
use Modules\Calendar\Internal\Services\CalendarMonthWindow;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;

// Three windows, one definition. The grid draws to the last cell of the
// ceiling month's Mon–Sun strip; the balance line is supplied by a projection
// that stops a fixed number of days out; the empty state tests whether the
// reader has anything the grid would draw. Counted off independently, the
// ceiling reached past the projection on 364 of 365 today-values (worst case
// 37 cells, all rendering "—" under an aria-live strip reading "Projection
// updating…" with nothing in flight), and the empty state went blind to the
// last strip's lead-out days on 304 of them.

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function horizonUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function horizonBookedRow(User $user, string $postedAt): void
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Horizon ASN',
        'slug' => 'hor-asn-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00HOR'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/hor-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'hor-'.$user->id),
        'uploaded_at' => '2020-01-01 00:00:00',
        'status' => 'committed',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'hor-'.$user->id.'-'.$postedAt),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'type' => 'expense',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Horizon Merchant',
        'counterparty_normalized' => 'horizon merchant',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => 0,
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);
}

// The first of a month, the last of a 31-day month, a leap day and a mid-month
// day: the gap between a month-counted ceiling and the projection swings with
// the day of the month, so a single pinned date proves nothing.
/** @return list<string> */
function horizonProbeDays(): array
{
    return ['2026-08-01', '2026-08-30', '2026-08-31', '2027-01-01', '2028-02-29', '2026-06-15'];
}

it('never lets the ceiling month draw a cell the projection has not reached', function (): void {
    $window = app(CalendarMonthWindow::class);
    $past = [];

    foreach (horizonProbeDays() as $today) {
        CarbonImmutable::setTestNow($today.' 12:00:00');

        $gridEnd = $window->lastDrawableDay();
        $lastProjected = $window->lastProjectedDay();

        if ($gridEnd->greaterThan($lastProjected)) {
            $past[] = $today.': grid ends '.$gridEnd->toDateString().', projection ends '.$lastProjected->toDateString();
        }
    }

    expect($past)->toBe(
        [],
        "Every cell the grid draws must have a projection point behind it, or it\n".
        'renders "—" and holds the summary strip on "Projection updating…". Past the projection:',
    );
});

// Reaching far enough is half of it: a ceiling that stopped short would pass
// the test above and quietly cost the reader a month of navigation.
it('reaches the furthest month whose whole strip still fits inside the projection', function (): void {
    $window = app(CalendarMonthWindow::class);
    $short = [];

    foreach (horizonProbeDays() as $today) {
        CarbonImmutable::setTestNow($today.' 12:00:00');

        $nextUp = $window->ceilingMonth()->addMonth();
        if (CalendarGrid::endFor($nextUp)->lessThanOrEqualTo($window->lastProjectedDay())) {
            $short[] = $today.': '.$nextUp->format('Y-m').' fits and is refused';
        }
    }

    expect($short)->toBe([], 'The ceiling stops short of a month the projection covers:');
});

it('asks the empty state over exactly the days the grid draws', function (): void {
    $window = app(CalendarMonthWindow::class);

    foreach (['2026-08-30', '2028-02-29', '2026-08-01'] as $index => $today) {
        CarbonImmutable::setTestNow($today.' 12:00:00');
        $lastDrawn = $window->lastDrawableDay();

        $inside = horizonUser('horizon-in-'.$index);
        horizonBookedRow($inside, $lastDrawn->toDateString());

        $outside = horizonUser('horizon-out-'.$index);
        horizonBookedRow($outside, $lastDrawn->addDay()->toDateString());

        expect(app(CalendarQuery::class)->hasProjectableEntries($inside))
            ->toBeTrue('a row on the last drawn cell '.$lastDrawn->toDateString().', on '.$today)
            ->and(app(CalendarQuery::class)->hasProjectableEntries($outside))
            ->toBeFalse('a row one day past the last drawn cell, on '.$today);
    }
});

// The lead-out days of the ceiling month's strip belong to the NEXT month, and
// bounding the probe at endOfMonth() left them out: the grid drew the charge
// while the banner over it read "No upcoming payments".
it('sees a booked row on a lead-out cell of the ceiling month', function (): void {
    CarbonImmutable::setTestNow('2026-08-30 12:00:00');

    $window = app(CalendarMonthWindow::class);
    $ceilingMonthEnd = $window->ceilingMonth()->endOfMonth()->startOfDay();
    $lastDrawn = $window->lastDrawableDay();

    expect($lastDrawn->greaterThan($ceilingMonthEnd))->toBeTrue('the chosen date has lead-out cells to test');

    $user = horizonUser('horizon-lead-out');
    horizonBookedRow($user, $lastDrawn->toDateString());

    expect(app(CalendarQuery::class)->hasProjectableEntries($user))->toBeTrue();
});
