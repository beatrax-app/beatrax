<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// dayInMonth() used to read the clock again instead of the window it was
// handed, so a seed crossing midnight into a new month pushed every row past
// windowEnd and lost the newest month. The crossing is unreachable from
// outside the seeder, so these assertions pin the agreement instead.
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

// The month count is what would have caught the drift: rows anchored to a
// second clock read fall outside the three months the window covers.
it('spans exactly the three consecutive months the window covers', function (): void {
    $months = array_values(array_unique(array_map(
        static fn (string $d): string => CarbonImmutable::parse($d)->format('Y-m'),
        demoWindowPostedDates(),
    )));
    sort($months);

    expect($months)->toBe(['2026-05', '2026-06', '2026-07']);
});
