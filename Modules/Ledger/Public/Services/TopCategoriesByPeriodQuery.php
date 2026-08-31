<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Core\Models\User;
use Modules\Ledger\Internal\Services\ConvertedSpendByCategory;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategories;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\Support\OutwardSpend;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#topcategoriesbyperiodquery--breadcrumb-category-tree-walk
 */
final readonly class TopCategoriesByPeriodQuery
{
    public function __construct(
        private ConvertedSpendByCategory $convertedSpend,
        private CategoryAncestry $ancestry,
        private BaseCurrency $baseCurrency,
    ) {}

    public function for(User $user, Period $period, ?string $displayCurrency = null, int $limit = 5): TopCategories
    {
        $displayCurrency ??= $this->baseCurrency->code();

        // The shared service returns an unordered map of signed net spend, so
        // the cutoff, the ordering, the limit and the share all come out of the
        // one narrowing rather than each being decided here.
        $spend = OutwardSpend::from(
            $this->convertedSpend->forUserAndPeriod($user->id, $period, $displayCurrency)->byCategoryId,
            $limit,
        );

        $categoriesById = $this->ancestry->load(array_keys($spend->rankedMinor), $user->id);

        $rows = [];
        foreach ($spend->rankedMinor as $categoryId => $spendMinor) {
            if (! isset($categoriesById[$categoryId])) {
                continue;
            }

            $rows[] = new TopCategoryRow(
                categoryId: $categoryId,
                name: $this->ancestry->fullPath($categoryId, $categoriesById),
                spend: Money::ofMinor($spendMinor, $displayCurrency),
                percentageOfTotal: $spend->shareOf($spendMinor),
            );
        }

        return new TopCategories(
            rows: $rows,
            refunded: Money::ofMinor(abs($spend->inwardMinor), $displayCurrency),
            refundedCategoryCount: $spend->inwardCount,
        );
    }
}
