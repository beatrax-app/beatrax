<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SnoozeUntil;

// The accept side of the same month-overflow family as SnoozeWindow: a plain
// addMonths() off a day the target month does not have rolls FORWARD past it,
// so on 31 August the ceiling sat on 3 March and let through three days the
// refusal underneath it says are out of bounds.

it('refuses a target past the six months the ceiling names, from a month end', function (): void {
    $now = CarbonImmutable::parse('2026-08-31 12:00:00');

    expect(SnoozeUntil::tryFrom(CarbonImmutable::parse('2027-03-02 12:00:00'), $now))->toBeNull()
        ->and(SnoozeUntil::tryFrom(CarbonImmutable::parse('2027-02-28 00:00:00'), $now))->not->toBeNull();
});

it('draws the ceiling on the last day of the sixth month whatever day it is asked on', function (): void {
    foreach (['2026-08-29', '2026-08-30', '2026-08-31'] as $today) {
        $now = CarbonImmutable::parse($today.' 12:00:00');
        $ceiling = $now->addMonthsNoOverflow(SnoozeUntil::MAX_MONTHS);

        expect(SnoozeUntil::tryFrom($ceiling, $now))->not->toBeNull('at the ceiling, on '.$today)
            ->and(SnoozeUntil::tryFrom($ceiling->addDay(), $now))->toBeNull('past the ceiling, on '.$today);
    }
});

it('still accepts an ordinary target inside the window', function (): void {
    $now = CarbonImmutable::parse('2026-08-15 12:00:00');

    expect(SnoozeUntil::from(CarbonImmutable::parse('2026-11-15 12:00:00'), $now)->toDateTimeString())
        ->toBe('2026-11-15 12:00:00');
});
