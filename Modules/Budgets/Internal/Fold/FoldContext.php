<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Fold;

final readonly class FoldContext
{
    /**
     * @param  array<int, array{name: string, path: string, slug: string, isDefault: bool}>  $expenseCategories
     * @param  array<int, string>  $overspendModeByCategory
     * @param  array<int, int>  $notifyThresholdByCategory
     */
    public function __construct(
        public array $expenseCategories,
        public array $overspendModeByCategory,
        public array $notifyThresholdByCategory,
    ) {}
}
