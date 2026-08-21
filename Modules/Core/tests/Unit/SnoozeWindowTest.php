<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Enums\SnoozeWindow;

it('builds the same three targets the two review pages built by hand', function (): void {
    $now = CarbonImmutable::parse('2026-08-21T09:30:00+02:00');

    expect(SnoozeWindow::targetsFrom($now))->toBe([
        '1w' => $now->addWeek()->toIso8601String(),
        '1m' => $now->addMonth()->toIso8601String(),
        '3m' => $now->addMonths(3)->toIso8601String(),
    ]);
});

it('measures every window from the same moment, never from the previous one', function (): void {
    $now = CarbonImmutable::parse('2026-01-31T00:00:00+00:00');
    $targets = SnoozeWindow::targetsFrom($now);

    expect($targets['1w'])->toBe('2026-02-07T00:00:00+00:00');
    expect($targets['1m'])->toBe('2026-03-03T00:00:00+00:00');
    expect($targets['3m'])->toBe('2026-05-01T00:00:00+00:00');
});

it('carries Carbon month-end overflow through unchanged, as the hand-written map did', function (): void {
    $now = CarbonImmutable::parse('2026-01-31T00:00:00+00:00');

    expect(SnoozeWindow::OneMonth->targetFrom($now))->toBe($now->addMonth()->toIso8601String());
    expect(SnoozeWindow::ThreeMonths->targetFrom($now))->toBe($now->addMonths(3)->toIso8601String());
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
