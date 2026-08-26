<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Core\Models\User;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#topcategoriesbyperiodquery--breadcrumb-category-tree-walk
 */
final class TopCategoriesByPeriodQuery
{
    public function __construct(
        private readonly SpendByCategoryQuery $spendByCategory,
        private readonly CategoryAncestry $ancestry,
        private readonly BaseCurrency $baseCurrency,
        private readonly CrossCurrencyTotal $fx,
    ) {}

    /**
     * @return array<TopCategoryRow>
     */
    public function for(User $user, Period $period, ?string $displayCurrency = null, int $limit = 5): array
    {
        $displayCurrency ??= $this->baseCurrency->code();

        // The shared service returns an unordered map, so DESC-by-spend
        // ordering + limit are re-applied here in PHP.
        $spendByCategoryId = $this->convertedSpend($user->id, $period, $displayCurrency);

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

    // Spend arrives bucketed by the currency each row was settled in, and each
    // bucket is converted before the category's buckets are added: reading only
    // the buckets already in the reporting currency showed a reader whose
    // accounts are denominated elsewhere no spend at all.
    /**
     * @return array<int, int>
     */
    private function convertedSpend(int $userId, Period $period, string $displayCurrency): array
    {
        $buckets = [];
        foreach ($this->spendByCategory->forUserAndPeriodByCurrency($userId, $period) as $key => $spendMinor) {
            [$categoryId, $currency] = explode('|', $key, 2) + [1 => ''];
            $buckets[(int) $categoryId][$currency] = $spendMinor;
        }

        $currencies = [];
        foreach ($buckets as $byCurrency) {
            foreach (array_keys($byCurrency) as $currency) {
                $currencies[] = $currency;
            }
        }
        $rates = $this->fx->ratesTo($currencies, $displayCurrency);

        $spendByCategoryId = [];
        foreach ($buckets as $categoryId => $byCurrency) {
            $spendByCategoryId[$categoryId] = $this->fx->withRates($byCurrency, $displayCurrency, $rates)->minor;
        }

        return $spendByCategoryId;
    }
}
