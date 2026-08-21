<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Fold;

final class FoldContext
{
    /**
     * @param  array<int, array{name: string, slug: string, isDefault: bool}>  $expenseCategories
     * @param  array<int, string>  $overspendModeByCategory
     * @param  array<int, int>  $notifyThresholdByCategory
     */
    public function __construct(
        public readonly array $expenseCategories,
        public readonly array $overspendModeByCategory,
        public readonly array $notifyThresholdByCategory,
    ) {}
}
