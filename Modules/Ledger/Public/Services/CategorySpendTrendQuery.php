<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Dto\CategoryDelta;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\SpendTrend;
use Modules\Ledger\Public\Support\CategoryDisplayName;

// Spend is EUR-settled outflow over [start, endExclusive), the same definition
// the rest of the ledger uses, so the figures reconcile with the dashboard.
final class CategorySpendTrendQuery
{
    use CoercesScalars;

    private const DISPLAY_CURRENCY = 'EUR';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PeriodQuery $periods,
        private readonly SpendByCategoryQuery $spendByCategoryQuery,
    ) {}

    public function forUser(User $user, int $moverLimit = 6): SpendTrend
    {
        $current = $this->periods->current();
        $previous = $this->periods->previous($current);

        $currentSpend = $this->spendByCategory($user->id, $current);
        $previousSpend = $this->spendByCategory($user->id, $previous);

        $names = $this->categoryNames(array_keys($currentSpend + $previousSpend), $user->id);

        $movers = [];
        foreach ($currentSpend + $previousSpend as $categoryId => $_) {
            $currentMinor = $currentSpend[$categoryId] ?? 0;
            $previousMinor = $previousSpend[$categoryId] ?? 0;
            $delta = $currentMinor - $previousMinor;
            if ($delta === 0) {
                continue;
            }
            $movers[] = new CategoryDelta(
                categoryId: $categoryId,
                name: $names[$categoryId] ?? Lang::get('ledger::common.uncategorized'),
                currentMinor: $currentMinor,
                previousMinor: $previousMinor,
                deltaMinor: $delta,
            );
        }

        usort($movers, static fn (CategoryDelta $a, CategoryDelta $b): int => abs($b->deltaMinor) <=> abs($a->deltaMinor));

        return new SpendTrend(
            currentTotalMinor: array_sum($currentSpend),
            previousTotalMinor: array_sum($previousSpend),
            totalDeltaMinor: array_sum($currentSpend) - array_sum($previousSpend),
            currency: self::DISPLAY_CURRENCY,
            currentLabel: $current->label,
            previousLabel: $previous->label,
            movers: array_slice($movers, 0, $moverLimit),
        );
    }

    /**
     * @return array<int, int> category_id => spend (EUR minor, positive)
     */
    private function spendByCategory(int $userId, Period $period): array
    {
        // A split transaction's legs count individually, never the parent row,
        // and uncategorised outflow lands under id 0 so the total stays whole.
        return $this->spendByCategoryQuery->forUserAndPeriod($userId, $period, self::DISPLAY_CURRENCY, includeUncategorized: true);
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

        $rows = $this->db->connection()->table('categories')
            ->whereIn('id', array_values($categoryIds))
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->get(['id', 'name', 'slug', 'name_is_default']);

        $names = [];
        foreach ($rows as $row) {
            $names[self::toInt($row->id)] = CategoryDisplayName::fromRow($row)
                ?? Lang::get('ledger::common.uncategorized');
        }

        return $names;
    }
}
