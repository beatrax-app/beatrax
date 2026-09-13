<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Internal\Dto\ConvertedCategorySpend;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Support\ConvertedBuckets;

// Spend arrives bucketed by the currency each row settled in. Filtering those
// buckets to the reader's display currency instead of converting them made the
// trend card read EUR 1,602.45 under an OUT tile reading EUR 1,608.74 one card
// away, the difference being a single JPY 1,000 row.
/**
 * @link ../../../../.docs/features/ledger/architecture.md#conversion-is-grouped-by-currency-not-by-category
 */
final readonly class ConvertedSpendByCategory
{
    public function __construct(
        private SpendByCategoryQuery $spendByCategory,
        private CrossCurrencyTotal $fx,
    ) {}

    public function forUserAndPeriod(int $userId, Period $period, string $displayCurrency, bool $includeUncategorized = false): ConvertedCategorySpend
    {
        /** @var array<int, array<string, int>> $buckets */
        $buckets = [];
        $currencies = [];
        foreach ($this->spendByCategory->forUserAndPeriodByCurrency($userId, $period, $includeUncategorized) as $key => $spendMinor) {
            [$categoryId, $currency] = explode('|', $key, 2) + [1 => ''];
            $buckets[(int) $categoryId][$currency] = $spendMinor;
            $currencies[] = $currency;
        }

        $rates = $this->fx->ratesTo($currencies, $displayCurrency);
        $converted = ConvertedBuckets::of($this->fx, $buckets, $displayCurrency, $rates);

        // Narrowed to the buckets that reached the figures: the spread is
        // all-or-nothing per currency, so a code in $unconverted moved none of
        // these rows and its rate says nothing about them.
        return new ConvertedCategorySpend(
            $converted->minorByKey,
            $converted->unconverted,
            $converted->conversion,
        );
    }
}
