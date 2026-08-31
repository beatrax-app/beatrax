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

// The one case in between used to be 15000/60000, which is exactly 25 and so
// reads the same whether the figure is rounded or floored. Every share that
// distinguishes the two is above 99, which is where the defect lived.
it('never reaches 100 from below, however close the sum gets', function (string $label, int $contributed): void {
    expect(progressRow(500000, $contributed)->percentComplete())->toBe(99);
})->with([
    'five euro short' => ['five euro short', 499500],
    'fifty cents short' => ['fifty cents short', 499950],
    'one cent short' => ['one cent short', 499999],
]);

it('reports 100 only when the sum has actually reached the target', function (): void {
    expect(progressRow(500000, 500000)->percentComplete())->toBe(100);
});

it('floors a share rather than rounding it up', function (): void {
    // 2/3 rounds to 67 and floors to 66; a bar may claim the smaller.
    expect(progressRow(300, 200)->percentComplete())->toBe(66);
});

// The sliver asked the PERCENTAGE whether anything was there, and a share under
// half a percent floors to zero — so the rule that exists to draw a visible
// mark for a real contribution drew nothing for every goal below 0.5%.
it('draws the sliver for a real share the percentage rounds away', function (): void {
    $row = progressRow(1000000, 4000);

    expect($row->percentComplete())->toBe(0)
        ->and($row->barWidth())->toBe(2);
});

it('draws nothing at all for a goal with nothing in it', function (): void {
    expect(progressRow(1000000, 0)->barWidth())->toBe(0);
});

it('draws nothing for a share a withdrawal has taken negative', function (): void {
    expect(progressRow(1000000, -4000)->barWidth())->toBe(0);
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
