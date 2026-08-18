<?php

declare(strict_types=1);

use Modules\Goals\Public\Dto\GoalProgressRow;

function progressRow(int $targetMinor, int $contributedMinor): GoalProgressRow
{
    return new GoalProgressRow(
        id: 1,
        name: 'Winterbanden',
        targetMinor: $targetMinor,
        contributedMinor: $contributedMinor,
        currency: 'EUR',
        fractionComplete: $targetMinor > 0 ? $contributedMinor / $targetMinor : 0.0,
        targetDate: '2026-12-31',
        status: 'active',
        progressState: 'in_progress',
        projectedFinishDate: null,
        projectionBeyondHorizon: false,
    );
}

it('floors progress at zero when a withdrawal takes the sum negative', function (): void {
    expect(progressRow(60000, -2600)->percentComplete())->toBe(0);
});

it('caps progress at 100 when the sum overshoots the target', function (): void {
    expect(progressRow(60000, 90000)->percentComplete())->toBe(100);
});

it('reports the real percentage in between', function (): void {
    expect(progressRow(60000, 15000)->percentComplete())->toBe(25);
});

it('reports zero rather than dividing when a goal has no target', function (): void {
    expect(progressRow(0, 2600)->percentComplete())->toBe(0);
});

it('keeps the contributed amount signed so the money line can show the withdrawal', function (): void {
    expect(progressRow(60000, -2600)->contributedMinor)->toBe(-2600);
});

it('reports the full target as remaining while the sum is negative', function (): void {
    expect(progressRow(60000, -2600)->remainingMinor())->toBe(62600);
});
