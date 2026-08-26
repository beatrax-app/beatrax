<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Recurring\Public\Support\MatchWindow;
use Modules\Recurring\Public\Support\OccurrenceSupersession;

/**
 * @param  list<string>  $dates
 * @return list<CarbonImmutable>
 */
function osDays(array $dates): array
{
    return array_map(static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date), $dates);
}

it('supersedes at most one expected date per booked row', function (): void {
    $superseded = OccurrenceSupersession::supersededDates(
        osDays(['2026-08-30']),
        osDays(['2026-08-30', '2026-09-06', '2026-09-13']),
    );

    expect(array_keys($superseded))->toBe(['2026-08-30']);
});

it('spends a second booked row on a second expected date', function (): void {
    $superseded = OccurrenceSupersession::supersededDates(
        osDays(['2026-08-30', '2026-09-06']),
        osDays(['2026-08-30', '2026-09-06', '2026-09-13']),
    );

    expect(array_keys($superseded))->toBe(['2026-08-30', '2026-09-06']);
});

it('takes the nearest expected date, not the first in range', function (): void {
    $superseded = OccurrenceSupersession::supersededDates(
        osDays(['2026-09-05']),
        osDays(['2026-08-30', '2026-09-06', '2026-09-13']),
    );

    expect(array_keys($superseded))->toBe(['2026-09-06']);
});

// Two expected dates a full window either side of one booked row is the tie the
// pairing has to settle on something other than the order they arrived in.
it('settles a tie on the earlier expected date, whatever order it was given', function (): void {
    $tied = ['2026-09-01', '2026-09-15'];
    $booked = osDays(['2026-09-08']);

    expect(array_keys(OccurrenceSupersession::supersededDates($booked, osDays($tied))))->toBe(['2026-09-01'])
        ->and(array_keys(OccurrenceSupersession::supersededDates($booked, osDays(array_reverse($tied)))))->toBe(['2026-09-01']);
});

it('leaves an expected date no booked row is near', function (): void {
    $outOfRange = CarbonImmutable::parse('2026-08-30')->addDays(MatchWindow::DAYS + 1)->toDateString();

    $superseded = OccurrenceSupersession::supersededDates(
        osDays(['2026-08-30']),
        osDays([$outOfRange]),
    );

    expect($superseded)->toBe([]);
});

// CadenceJitter spreads one occurrence over consecutive days, so the run around
// the day a booked row claims is that one payment and goes with it.
it('takes the whole consecutive-day run around the date it claims', function (): void {
    $superseded = OccurrenceSupersession::supersededDates(
        osDays(['2026-09-01']),
        osDays(['2026-08-29', '2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-10-01']),
    );

    expect(array_keys($superseded))->toBe([
        '2026-08-29', '2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04',
    ]);
});

it('stops the run at a gap rather than walking on to the next occurrence', function (): void {
    $superseded = OccurrenceSupersession::supersededDates(
        osDays(['2026-09-01']),
        osDays(['2026-08-31', '2026-09-01', '2026-09-03', '2026-09-06']),
    );

    expect(array_keys($superseded))->toBe(['2026-08-31', '2026-09-01']);
});
