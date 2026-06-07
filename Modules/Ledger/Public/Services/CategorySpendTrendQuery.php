<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CategoryDelta;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\SpendTrend;

/**
 * Month-over-month spend comparison (SEED-006): the current period's categorised
 * outflow versus the previous period's, plus the categories that moved the most.
 *
 * Spend is the same definition the rest of the ledger uses — EUR-settled
 * outflow (`settled_amount_minor < 0`) within the period's [start, endExclusive)
 * window — so the figures reconcile with "this month at a glance". Every read is
 * scoped to the user.
 */
final class CategorySpendTrendQuery
{
    private const DISPLAY_CURRENCY = 'EUR';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PeriodQuery $periods,
    ) {}

    public function forUser(User $user, int $moverLimit = 6): SpendTrend
    {
        $current = $this->periods->current();
        $previous = $this->periods->previous($current);

        $currentSpend = $this->spendByCategory($user->id, $current);
        $previousSpend = $this->spendByCategory($user->id, $previous);

        $names = $this->categoryNames(array_keys($currentSpend + $previousSpend));

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
                name: $names[$categoryId] ?? 'Uncategorized',
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
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('settled_currency', self::DISPLAY_CURRENCY)
            ->where('settled_amount_minor', '<', 0)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->get(['category_id', $this->db->connection()->raw('SUM(-settled_amount_minor) AS spend_minor')]);

        $map = [];
        foreach ($rows as $row) {
            $map[self::toInt($row->category_id)] = self::toInt($row->spend_minor);
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, string>
     */
    private function categoryNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $this->db->connection()->table('categories')
            ->whereIn('id', array_values($categoryIds))
            ->get(['id', 'name']);

        $names = [];
        foreach ($rows as $row) {
            $names[self::toInt($row->id)] = is_string($row->name) ? $row->name : 'Uncategorized';
        }

        return $names;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
