<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Database\Seeders\Demo\DemoPeriodWindow;
use Modules\Ledger\Public\Services\CalendarSpan;

uses(RefreshDatabase::class);

// The seeded rows and the budget grid drawn over them are one window, taken
// once from DemoPeriodWindow. Counted off in calendar months here and in
// budget periods there, the two agreed only for a reader whose period opens on
// the 1st; the persona keeping period_start_day 25 got a grid with none of its
// own spend in it.
beforeEach(function (): void {
    // A month-end date on purpose — it is where subMonthsNoOverflow and
    // addMonthsNoOverflow disagree most, and where a rollover is closest.
    CarbonImmutable::setTestNow('2026-07-31 23:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

// Seeded inside each test rather than from beforeEach, so a test that binds
// its own Clock binds it before anything resolves the seeders that read it.
function seedTheDemoDatasetForWindowTest(): void
{
    Artisan::call('demo:seed');
}

/**
 * @return list<string>
 */
function demoWindowPostedDates(int $userId): array
{
    return array_values(array_map(
        static fn (mixed $d): string => (string) $d,
        DB::table('transactions')
            ->where('source_format', 'demo')
            ->where('user_id', $userId)
            ->pluck('posted_at')
            ->all(),
    ));
}

function demoWindowFixedClock(CarbonImmutable $instant): Clock
{
    return new class($instant) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $instant) {}

        public function now(): CarbonImmutable
        {
            return $this->instant;
        }
    };
}

function demoWindowStringValue(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

// A notification stores its dates inside the rendered copy payload, so the
// business date it was raised about is only reachable through the JSON. The
// whole payload is returned when there is none, so a miss reads as the row
// that failed rather than as an empty string.
function demoWindowFirstDateIn(string $payload): string
{
    return preg_match('/"value":"(\\d{4}-\\d{2}-\\d{2})"/', $payload, $matches) === 1
        ? $matches[1]
        : $payload;
}

// Both personas: they differ only in period_start_day, which is the whole
// axis the two windows used to disagree on.
dataset('seeded personas', ['demo-1', 'demo-2']);

it('places every demo transaction inside the periods the budget grid draws', function (string $username): void {
    seedTheDemoDatasetForWindowTest();

    /** @var User $user */
    $user = User::query()->where('username', $username)->firstOrFail();
    $periods = app(DemoPeriodWindow::class)->forUser($user, CarbonImmutable::now());

    $windowStart = $periods[0]->start;
    $windowEnd = CalendarSpan::lastDayOf($periods[count($periods) - 1]);

    $dates = demoWindowPostedDates((int) $user->id);
    expect($dates)->not->toBeEmpty();

    $outside = [];
    foreach ($dates as $date) {
        $posted = CarbonImmutable::parse($date)->startOfDay();
        if ($posted->lessThan($windowStart) || $posted->greaterThan($windowEnd)) {
            $outside[] = $date;
        }
    }

    expect($outside)->toBe(
        [],
        $username.' seeded rows outside '.$windowStart->toDateString().' … '.$windowEnd->toDateString()
            .', which is the span its budgets grid can navigate:',
    );
})->with('seeded personas');

// Every seeded period has to hold rows, not merely the union of them: a span
// whose oldest period is empty gives the fold nothing to carry.
it('puts rows in each of the periods it seeds', function (string $username): void {
    seedTheDemoDatasetForWindowTest();

    /** @var User $user */
    $user = User::query()->where('username', $username)->firstOrFail();
    $periods = app(DemoPeriodWindow::class)->forUser($user, CarbonImmutable::now());
    expect($periods)->toHaveCount(DemoPeriodWindow::SPAN);

    $dates = demoWindowPostedDates((int) $user->id);
    $empty = [];

    foreach ($periods as $period) {
        $inPeriod = array_filter($dates, static function (string $date) use ($period): bool {
            $posted = CarbonImmutable::parse($date)->startOfDay();

            return $posted->greaterThanOrEqualTo($period->start) && $posted->lessThan($period->endExclusive);
        });

        if ($inPeriod === []) {
            $empty[] = $period->start->toDateString();
        }
    }

    expect($empty)->toBe([], $username.' seeded no rows at all into these periods:');
})->with('seeded personas');

// Half the demo seeders used to call CarbonImmutable::today() for themselves
// while the rest were handed the Clock, so one run could write two datasets an
// hour apart at a rollover — and nothing on a same-day run could tell. Holding
// the injected instant four and a half months off the wall clock is what makes
// the two sources distinguishable: every date below is derived from the
// injected one, and a seeder still reading the wall clock lands in August.
it('writes every seeded date off the injected clock, never the wall clock', function (): void {
    $injected = CarbonImmutable::parse('2026-03-11 09:00:00');
    app()->instance(Clock::class, demoWindowFixedClock($injected));

    seedTheDemoDatasetForWindowTest();

    /** @var User $demoUser */
    $demoUser = User::query()->where('username', 'demo-1')->firstOrFail();
    $kpnReminder = DB::table('notifications')
        ->where('user_id', $demoUser->id)
        ->where('trigger_type', 'payment_reminder')
        ->where('params', 'like', '%KPN%')
        ->value('params');

    $observed = [
        'transfer pair, day 11 of the middle seeded period' => demoWindowStringValue(
            DB::table('transactions')->where('source_ref', 'DEMO-PAIR-OUT-1')->value('posted_at'),
        ),
        'newest cash-book entry, 3 days back' => demoWindowStringValue(
            DB::table('transactions')->where('source_format', 'manual')->max('posted_at'),
        ),
        'furthest goal deadline, 18 months out' => demoWindowStringValue(
            DB::table('goals')->max('target_date'),
        ),
        'oldest goal start, 80 days back' => demoWindowStringValue(
            DB::table('goals')->min('start_date'),
        ),
        'Spotify next charge, day 11 of next month' => demoWindowStringValue(
            DB::table('recurring_series')->where('cluster_key', 'demo:spotify:monthly:1099')->value('next_expected_at'),
        ),
        'newest series transition, 2 days back at noon' => demoWindowStringValue(
            DB::table('recurring_series_transitions')->max('transitioned_at'),
        ),
        'seeded shortfall window opens, 18 days out' => demoWindowStringValue(
            DB::table('forecast_shortfall_windows')->value('starts_at'),
        ),
        'oldest system alert, 240 hours back' => demoWindowStringValue(
            DB::table('system_alerts')->min('created_at'),
        ),
        'KPN payment reminder falls due, 3 days out' => demoWindowFirstDateIn(
            demoWindowStringValue($kpnReminder),
        ),
        'PayPal receipt arrived, 48 hours back' => demoWindowStringValue(
            DB::table('file_imports')->where('provider_message_id', 'demo-paypal-receipt-001')->value('internal_date'),
        ),
        'Spotify known sender added, 3 days back' => demoWindowStringValue(
            DB::table('known_senders')->where('email_pattern', 'subscriptions@spotify.com')->value('added_at'),
        ),
        'newest recovery code used, 24 hours back' => demoWindowStringValue(
            DB::table('user_recovery_codes')->max('used_at'),
        ),
        'newest wizard step completed, 48 hours back' => demoWindowStringValue(
            DB::table('wizard_progress')->max('completed_at'),
        ),
        'merchant memory last seen, now' => demoWindowStringValue(
            DB::table('merchant_memories')->max('last_seen_at'),
        ),
        'Albert Heijn alias written, now' => demoWindowStringValue(
            DB::table('merchant_aliases')->where('pattern', 'ah filiaal')->value('created_at'),
        ),
        'tax year override, last calendar year' => demoWindowStringValue(
            DB::table('tax_transaction_tags')->max('tax_year_override'),
        ),
    ];

    expect($observed)->toBe([
        'transfer pair, day 11 of the middle seeded period' => '2026-02-11',
        'newest cash-book entry, 3 days back' => '2026-03-08',
        'furthest goal deadline, 18 months out' => '2027-09-11',
        'oldest goal start, 80 days back' => '2025-12-21',
        'Spotify next charge, day 11 of next month' => '2026-04-11',
        'newest series transition, 2 days back at noon' => '2026-03-09 12:00:00',
        'seeded shortfall window opens, 18 days out' => '2026-03-29',
        'oldest system alert, 240 hours back' => '2026-03-01 09:00:00',
        'KPN payment reminder falls due, 3 days out' => '2026-03-14',
        'PayPal receipt arrived, 48 hours back' => '2026-03-09 09:00:00',
        'Spotify known sender added, 3 days back' => '2026-03-08 09:00:00',
        'newest recovery code used, 24 hours back' => '2026-03-10 09:00:00',
        'newest wizard step completed, 48 hours back' => '2026-03-09 09:00:00',
        'merchant memory last seen, now' => '2026-03-11 09:00:00',
        'Albert Heijn alias written, now' => '2026-03-11 09:00:00',
        'tax year override, last calendar year' => '2025',
    ], 'these seeded rows are dated off the wall clock (2026-07-31) instead of the injected one (2026-03-11):');
});
