<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Internal\Dto\ConvertedCategorySpend;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;

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
        /** @var array<string, array<int, int>> $byCurrency */
        $byCurrency = [];
        foreach ($this->spendByCategory->forUserAndPeriodByCurrency($userId, $period, $includeUncategorized) as $key => $spendMinor) {
            [$categoryId, $currency] = explode('|', $key, 2) + [1 => ''];
            $byCurrency[$currency][(int) $categoryId] = $spendMinor;
        }

        $rates = $this->fx->ratesTo(array_keys($byCurrency), $displayCurrency);

        $spendByCategoryId = [];
        $unconverted = [];
        foreach ($byCurrency as $currency => $partsByCategory) {
            // Null is a currency no rate reaches, named rather than counted at
            // one to one -- the choice the tile above these rows makes too.
            $converted = $this->fx->distribute($partsByCategory, $currency, $displayCurrency, $rates);

            if ($converted === null) {
                $unconverted[] = $currency;

                continue;
            }

            foreach ($converted as $categoryId => $spendMinor) {
                $spendByCategoryId[$categoryId] = ($spendByCategoryId[$categoryId] ?? 0) + $spendMinor;
            }
        }

        sort($unconverted);

        return new ConvertedCategorySpend(
            $spendByCategoryId,
            $unconverted,
            // Narrowed to the buckets that reached the figures: distribute()
            // is all-or-nothing per currency, so a code in $unconverted moved
            // none of these rows and its rate says nothing about them.
            ConversionDisclosure::of(
                $rates->only(array_values(array_diff(array_keys($byCurrency), $unconverted))),
                $unconverted,
            ),
        );
    }
}
