<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row on the Budgets page: a category with its monthly budget, the amount
 * spent against it in the current period, and the derived progress.
 *
 * `fractionUsed` is spent/budget, clamped to >= 0 (a category in net credit
 * for the period reads as 0% used). `status` buckets it for the bar colour:
 * `under` (< 80%), `near` (80–100%), `over` (> 100%). All money is signed
 * minor units in `currency`; the view wraps them in the Money value object
 * for locale formatting.
 */
final class BudgetProgressRow extends Data
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly int $budgetMinor,
        public readonly int $spentMinor,
        public readonly string $currency,
        public readonly float $fractionUsed,
        public readonly string $status,
    ) {}

    public function remainingMinor(): int
    {
        return $this->budgetMinor - $this->spentMinor;
    }
}
