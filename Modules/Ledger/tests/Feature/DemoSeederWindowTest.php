<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The demo dataset's dates and its window have to agree.
 *
 * DemoTransactionsSeeder::run() fixes a three-month window once, from a
 * single clock read, and then hands that window to every series it seeds.
 * dayInMonth() used to ignore the window it was passed and read the clock
 * again, so the dates were anchored to whenever that method happened to run
 * rather than to the window. Under a still clock the two agreed and nothing
 * showed; a seed crossing midnight into a new month shifted every row a month
 * forward while windowEnd stayed behind, which drops the newest month.
 *
 * These assertions pin the agreement rather than the crossing: the crossing
 * itself is not reachable from outside the seeder, but a date that no longer
 * derives from the window will fall outside it or change the month count.
 */
beforeEach(function (): void {
    // A month-end date on purpose — it is where subMonthsNoOverflow and
    // addMonthsNoOverflow disagree most, and where a rollover is closest.
    CarbonImmutable::setTestNow('2026-07-31 23:00:00');
    Artisan::call('demo:seed');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

/**
 * @return list<string>
 */
function demoWindowPostedDates(): array
{
    return array_values(array_map(
        static fn (mixed $d): string => (string) $d,
        DB::table('transactions')->where('source_format', 'demo')->pluck('posted_at')->all(),
    ));
}

it('places every demo transaction inside the window the seeder fixed', function (): void {
    $today = CarbonImmutable::today();
    $windowStart = $today->subMonthsNoOverflow(2)->startOfMonth();
    $windowEnd = $today->endOfMonth();

    $dates = demoWindowPostedDates();
    expect($dates)->not->toBeEmpty();

    foreach ($dates as $date) {
        $posted = CarbonImmutable::parse($date)->startOfDay();
        expect($posted->greaterThanOrEqualTo($windowStart))->toBeTrue()
            ->and($posted->lessThanOrEqualTo($windowEnd))->toBeTrue();
    }
});

// The month count is the assertion that would have caught the drift: an
// anchor read from a second clock lands the newest rows in a month the
// window does not cover, and the distinct-month set stops being the three
// consecutive months run() laid out.
it('spans exactly the three consecutive months the window covers', function (): void {
    $months = array_values(array_unique(array_map(
        static fn (string $d): string => CarbonImmutable::parse($d)->format('Y-m'),
        demoWindowPostedDates(),
    )));
    sort($months);

    expect($months)->toBe(['2026-05', '2026-06', '2026-07']);
});
