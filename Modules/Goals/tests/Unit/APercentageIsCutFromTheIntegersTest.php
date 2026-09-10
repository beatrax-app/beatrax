<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\Support\OutwardSpend;

function goalAt(int $targetMinor, int $contributedMinor): GoalProgressRow
{
    return new GoalProgressRow(
        id: 1,
        name: 'Deposit',
        targetMinor: $targetMinor,
        contributedMinor: $contributedMinor,
        currency: 'EUR',
        fractionComplete: OutwardSpend::share($contributedMinor, $targetMinor),
        targetDate: '2027-02-24',
        status: 'active',
        progressState: 'on_track',
        projectedFinishDate: null,
        projectionBeyondHorizon: false,
    );
}

// The share was a plain int/int, and the nearest double to 29/50 is
// 0.57999999999999996 -- times a hundred it lands below 58, and the floor took
// the point. Three of the hundred came out a point light: a goal at 29000 of
// 50000 announced "57% complete" beside a money line reading 290,00 / 500,00.
it('answers every whole percentage of a goal exactly', function (): void {
    $wrong = [];

    foreach (range(0, 100) as $percent) {
        $answered = goalAt(50000, $percent * 500)->percentComplete();

        if ($answered !== $percent) {
            $wrong[] = $percent.' answered as '.$answered;
        }
    }

    expect($wrong)->toBe([]);
});

it('answers every whole percentage of a category share exactly', function (): void {
    $wrong = [];

    foreach (range(0, 100) as $percent) {
        $row = new TopCategoryRow(
            categoryId: 1,
            name: 'Groceries',
            spend: Money::ofMinor($percent * 500, 'EUR'),
            percentageOfTotal: OutwardSpend::share($percent * 500, 50000),
            wholeMinor: 50000,
        );

        if ($row->percentOfTotal() !== $percent) {
            $wrong[] = $percent.' answered as '.$row->percentOfTotal();
        }
    }

    expect($wrong)->toBe([]);
});

// Floored, not rounded, stays: rounding reached a hundred from below and drew
// a full bar five euro short.
it('still floors a part-way percentage rather than rounding it', function (): void {
    expect(goalAt(50000, 49999)->percentComplete())->toBe(99)
        ->and(goalAt(50000, 1)->percentComplete())->toBe(0)
        ->and(goalAt(50000, 60000)->percentComplete())->toBe(100)
        ->and(goalAt(50000, -400)->percentComplete())->toBe(0);
});
