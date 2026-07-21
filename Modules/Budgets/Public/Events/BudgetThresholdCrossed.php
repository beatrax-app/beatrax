<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Events;

/**
 * @link ../../../../.docs/features/budgets/architecture.md
 */
final readonly class BudgetThresholdCrossed
{
    public function __construct(
        public int $userId,
        public int $categoryId,
        public string $categoryName,
        public string $period,
        public int $thresholdPercent,
        public int $spentMinor,
        public int $budgetMinor,
        public string $currency,
    ) {}
}
