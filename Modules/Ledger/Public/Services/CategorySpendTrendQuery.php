<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Dto\ConvertedCategorySpend;
use Modules\Ledger\Internal\Services\ConvertedSpendByCategory;
use Modules\Ledger\Public\Dto\CategoryDelta;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\SpendTrend;
use Modules\Ledger\Public\Support\CategoryPathName;

// Spend is outflow over [start, endExclusive) converted into the reader's
// display currency, the same definition the rest of the ledger uses, so the
// figures reconcile with the dashboard.
final readonly class CategorySpendTrendQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private PeriodQuery $periods,
        private ConvertedSpendByCategory $convertedSpend,
        private BaseCurrency $baseCurrency,
        private PopulatedPeriodQuery $populated,
    ) {}

    public function forUser(User $user, int $moverLimit = 6): SpendTrend
    {
        $displayCurrency = $this->baseCurrency->code();
        $current = $this->periods->current();
        $previous = $this->periods->previous($current);

        $currentSpend = $this->spendByCategory($user->id, $current, $displayCurrency);
        $previousSpend = $this->spendByCategory($user->id, $previous, $displayCurrency);

        $currentByCategory = $currentSpend->byCategoryId;
        $previousByCategory = $previousSpend->byCategoryId;

        $names = $this->categoryNames(array_keys($currentByCategory + $previousByCategory), $user->id);

        $movers = [];
        foreach ($currentByCategory + $previousByCategory as $categoryId => $_) {
            $currentMinor = $currentByCategory[$categoryId] ?? 0;
            $previousMinor = $previousByCategory[$categoryId] ?? 0;
            $delta = $currentMinor - $previousMinor;
            if ($delta === 0) {
                continue;
            }
            $movers[] = new CategoryDelta(
                categoryId: $categoryId,
                // Id 0 is the real "no category" bucket; any other id missing
                // from $names is one this device cannot see, which is a
                // different fact and used to borrow the same word.
                name: $names[$categoryId] ?? Lang::get($categoryId === 0 ? 'ledger::common.uncategorized' : 'ledger::common.unavailable_category'),
                currentMinor: $currentMinor,
                previousMinor: $previousMinor,
                deltaMinor: $delta,
            );
        }

        usort($movers, static fn (CategoryDelta $a, CategoryDelta $b): int => abs($b->deltaMinor) <=> abs($a->deltaMinor));

        return new SpendTrend(
            currentTotalMinor: array_sum($currentByCategory),
            previousTotalMinor: array_sum($previousByCategory),
            totalDeltaMinor: array_sum($currentByCategory) - array_sum($previousByCategory),
            currency: $displayCurrency,
            currentLabel: $current->label,
            previousLabel: $previous->label,
            movers: array_slice($movers, 0, $moverLimit),
            unconvertedCurrencies: self::mergeUnconverted($currentSpend, $previousSpend),
            // Asked of the ledger, not of the previous period's own total: a
            // month a reader genuinely spent nothing in is a real comparison
            // and must keep drawing, and so must a gap between two months of
            // records. Only a period the ledger never reached is not one.
            previousPeriodIsReachable: $this->populated->reachesBackInto($user, $previous),
        );
    }

    private function spendByCategory(int $userId, Period $period, string $displayCurrency): ConvertedCategorySpend
    {
        // A split transaction's legs count individually, never the parent row,
        // and uncategorised outflow lands under id 0 so the total stays whole.
        return $this->convertedSpend->forUserAndPeriod($userId, $period, $displayCurrency, includeUncategorized: true);
    }

    // The two periods are read separately, so a currency only last period held
    // would otherwise go unnamed beside a figure that leaves it out.
    /**
     * @return list<string>
     */
    private static function mergeUnconverted(ConvertedCategorySpend $current, ConvertedCategorySpend $previous): array
    {
        $codes = array_values(array_unique([...$current->unconvertedCurrencies, ...$previous->unconvertedCurrencies]));
        sort($codes);

        return $codes;
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, string>
     */
    private function categoryNames(array $categoryIds, int $userId): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = CategoryPathName::joinParent($this->db->connection()->table('categories as c'), $userId, 'c', 'cp')
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->get(['c.id', ...CategoryPathName::columns('c', 'cp')]);

        // Read wide, answer narrow: the ordinal separating two identical paths
        // counts from the lowest id of every visible category, so a legend
        // naming a subset still names it the way the pickers do.
        $paths = [];
        foreach ($rows as $row) {
            $paths[self::toInt($row->id)] = CategoryPathName::fromRow($row)
                ?? Lang::get('ledger::common.unavailable_category');
        }

        return array_intersect_key(CategoryPathName::distinct($paths), array_flip(array_values($categoryIds)));
    }
}
