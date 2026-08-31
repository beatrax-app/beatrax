<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Enums\SnoozeWindow;

it('builds the same three targets the two review pages built by hand', function (): void {
    $now = CarbonImmutable::parse('2026-08-21T09:30:00+02:00');

    expect(SnoozeWindow::targetsFrom($now))->toBe([
        '1w' => $now->addWeek()->toIso8601String(),
        '1m' => $now->addMonthNoOverflow()->toIso8601String(),
        '3m' => $now->addMonthsNoOverflow(3)->toIso8601String(),
    ]);
});

it('measures every window from the same moment, never from the previous one', function (): void {
    $now = CarbonImmutable::parse('2026-01-31T00:00:00+00:00');
    $targets = SnoozeWindow::targetsFrom($now);

    expect($targets['1w'])->toBe('2026-02-07T00:00:00+00:00');
    expect($targets['1m'])->toBe('2026-02-28T00:00:00+00:00');
    expect($targets['3m'])->toBe('2026-04-30T00:00:00+00:00');
});

// A plain addMonth() off a day the target month does not have rolls FORWARD
// past it: snoozed on 31 January, "one month" came back on 3 March, a third of
// the way into the month after the one the reader was offered.
it('clamps a month-end snooze onto the target month rather than past it', function (): void {
    foreach (['2026-01-29', '2026-01-30', '2026-01-31', '2026-03-31', '2026-05-31', '2026-08-31'] as $from) {
        $now = CarbonImmutable::parse($from.'T00:00:00+00:00');

        expect(substr($now->addMonthNoOverflow()->toIso8601String(), 0, 7))
            ->toBe(substr(SnoozeWindow::OneMonth->targetFrom($now), 0, 7), 'one month from '.$from)
            ->and(substr($now->addMonthsNoOverflow(3)->toIso8601String(), 0, 7))
            ->toBe(substr(SnoozeWindow::ThreeMonths->targetFrom($now), 0, 7), 'three months from '.$from);
    }
});

it('leaves a mid-month snooze on the same day of the target month', function (): void {
    $now = CarbonImmutable::parse('2026-01-15T00:00:00+00:00');
    $targets = SnoozeWindow::targetsFrom($now);

    expect($targets['1w'])->toBe('2026-01-22T00:00:00+00:00');
    expect($targets['1m'])->toBe('2026-02-15T00:00:00+00:00');
    expect($targets['3m'])->toBe('2026-04-15T00:00:00+00:00');
});

it('keys the map by the wire values the blades and the snooze methods exchange', function (): void {
    expect(array_map(
        static fn (SnoozeWindow $window): string => $window->value,
        SnoozeWindow::cases(),
    ))->toBe(['1w', '1m', '3m']);
});

it('names the label keys the nine buttons already used', function (): void {
    $keys = [];
    foreach (['anomaly::alerts.chips', 'drift-alerts::alerts.row', 'recurring::review'] as $group) {
        foreach (SnoozeWindow::cases() as $window) {
            $keys[] = $window->labelKey($group);
        }
    }

    expect($keys)->toBe([
        'anomaly::alerts.chips.snooze_1w',
        'anomaly::alerts.chips.snooze_1m',
        'anomaly::alerts.chips.snooze_3m',
        'drift-alerts::alerts.row.snooze_1w',
        'drift-alerts::alerts.row.snooze_1m',
        'drift-alerts::alerts.row.snooze_3m',
        'recurring::review.snooze_1w',
        'recurring::review.snooze_1m',
        'recurring::review.snooze_3m',
    ]);
});
