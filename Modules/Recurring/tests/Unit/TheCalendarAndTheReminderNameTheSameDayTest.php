<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Recurring\Internal\CadenceInferrer;
use Modules\Recurring\Public\Enums\SeriesCadence;

/**
 * @param  list<string>  $observed
 * @return array{anchor: string, billingDay: int, walk: list<string>, reminder: list<string>}
 */
function clampProbe(array $observed): array
{
    $timestamps = array_map(static fn (string $d): CarbonImmutable => CarbonImmutable::parse($d), $observed);
    $inferred = (new CadenceInferrer)->infer($timestamps);
    $anchor = $inferred->nextExpectedAt;
    expect($anchor)->not->toBeNull();

    // What the calendar grid and the forecast curve draw: SeriesCadence walked
    // forward from next_expected_at.
    $walk = [];
    for ($k = 1; $k <= 4; $k++) {
        $walk[] = SeriesCadence::Monthly->occurrenceAt($anchor, $k, $inferred->billingDay)?->toDateString();
    }

    // What the reminder names: CadenceInferrer re-run once each charge lands.
    $reminder = [];
    $series = [...$timestamps, $anchor];
    for ($i = 0; $i < 4; $i++) {
        $next = (new CadenceInferrer)->infer($series)->nextExpectedAt;
        $reminder[] = $next?->toDateString();
        $series[] = $next;
    }

    return [
        'anchor' => $anchor->toDateString(),
        'billingDay' => $inferred->billingDay ?? 0,
        'walk' => $walk,
        'reminder' => $reminder,
    ];
}

// next_expected_at is itself clamped when it lands in a month shorter than the
// billing day, and both walks anchor on it — so the anchor's day, not the
// series' own, used to decide every date after it.
it('agrees with the reminder for a 31st bill whose next date falls in February', function (): void {
    $probe = clampProbe(['2025-09-30', '2025-10-31', '2025-11-30', '2025-12-31', '2026-01-31']);

    expect($probe['anchor'])->toBe('2026-02-28')
        ->and($probe['billingDay'])->toBe(31)
        ->and($probe['walk'])->toBe($probe['reminder'])
        ->and($probe['walk'])->toBe(['2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30']);
});

it('agrees for a 30th bill whose next date falls in February, which the last-day rule would over-correct', function (): void {
    $probe = clampProbe(['2025-09-30', '2025-10-30', '2025-11-30', '2025-12-30', '2026-01-30']);

    expect($probe['anchor'])->toBe('2026-02-28')
        ->and($probe['billingDay'])->toBe(30)
        ->and($probe['walk'])->toBe($probe['reminder'])
        ->and($probe['walk'])->toBe(['2026-03-30', '2026-04-30', '2026-05-30', '2026-06-30']);
});

it('agrees when the anchor already carries the billing day', function (): void {
    $probe = clampProbe(['2025-10-31', '2025-11-30', '2025-12-31', '2026-01-31', '2026-02-28']);

    expect($probe['anchor'])->toBe('2026-03-31')
        ->and($probe['walk'])->toBe($probe['reminder']);
});

it('agrees for an ordinary mid-month bill', function (): void {
    $probe = clampProbe(['2025-10-15', '2025-11-15', '2025-12-15', '2026-01-15', '2026-02-15']);

    expect($probe['anchor'])->toBe('2026-03-15')
        ->and($probe['walk'])->toBe($probe['reminder']);
});

// Without a billing day there is nothing to restore, so the anchor's own day
// still rules — the calendar's history fill-in walks negative k the same way.
it('keeps the anchor day when no billing day is known', function (): void {
    $anchor = CarbonImmutable::parse('2026-02-28');

    expect(SeriesCadence::Monthly->occurrenceAt($anchor, 1)?->toDateString())->toBe('2026-03-28')
        ->and(SeriesCadence::Monthly->occurrenceAt($anchor, -1, 31)?->toDateString())->toBe('2026-01-31');
});
