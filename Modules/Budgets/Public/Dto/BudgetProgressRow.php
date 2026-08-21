<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Spatie\LaravelData\Data;

// status buckets fractionUsed for the bar colour: under (< 80%),
// near (80-100%), over (> 100%).
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
