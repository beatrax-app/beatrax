<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#topcategoriesbyperiodquery--breadcrumb-category-tree-walk
 */
final class TopCategoriesByPeriodQuery
{
    public const DEFAULT_DISPLAY_CURRENCY = 'EUR';

    public function __construct(
        private readonly SpendByCategoryQuery $spendByCategory,
        private readonly CategoryAncestry $ancestry,
    ) {}

    /**
     * @return array<TopCategoryRow>
     */
    public function for(User $user, Period $period, string $displayCurrency = self::DEFAULT_DISPLAY_CURRENCY, int $limit = 5): array
    {
        // The shared service returns an unordered map, so DESC-by-spend
        // ordering + limit are re-applied here in PHP.
        $spendByCategoryId = $this->spendByCategory->forUserAndPeriod($user->id, $period, $displayCurrency);

        if ($spendByCategoryId === []) {
            return [];
        }

        arsort($spendByCategoryId);
        $spendByCategoryId = array_slice($spendByCategoryId, 0, $limit, preserve_keys: true);

        $total = 0;
        foreach ($spendByCategoryId as $spendMinor) {
            $total += $spendMinor;
        }

        if ($total <= 0) {
            return [];
        }

        $categoryIds = array_keys($spendByCategoryId);
        $categoriesById = $this->ancestry->load($categoryIds, $user->id);

        $result = [];
        foreach ($spendByCategoryId as $categoryId => $spendMinor) {
            if (! isset($categoriesById[$categoryId])) {
                continue;
            }

            $result[] = new TopCategoryRow(
                categoryId: $categoryId,
                name: $this->ancestry->fullPath($categoryId, $categoriesById),
                spend: Money::ofMinor($spendMinor, $displayCurrency),
                percentageOfTotal: $spendMinor / $total,
            );
        }

        return $result;
    }
}
