<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Events;

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
        // $categoryName is resolved in whatever language the emitter was in,
        // and the emitter is an hourly job with no reader. These two let the
        // notification resolve it again in the one the recipient reads.
        public string $categorySlug = '',
        public bool $categoryNameIsDefault = false,
    ) {}
}
