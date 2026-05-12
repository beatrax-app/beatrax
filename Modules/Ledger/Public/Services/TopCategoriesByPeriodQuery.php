<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * Top-N spending categories for a given user + period window.
 *
 * Performs the aggregation as a single `GROUP BY category_id` with an
 * integer `SUM(-amount_minor)` so the database returns money totals as
 * integers — Money is composed at the DTO boundary (no float arithmetic
 * anywhere on the hot path).
 *
 * The `percentageOfTotal` field is each row's share of the panel's total
 * (not the user's overall outflow). It always sums to ~1.0 for non-empty
 * results, which keeps the dashboard's thin progress bar arithmetic free
 * of edge cases.
 *
 * Uses the raw `DatabaseManager` query builder rather than Eloquent
 * because the project applies `phpstan-strict-rules`'
 * `staticMethod.dynamicCall` rule (which forbids `Builder::count()`,
 * `Builder::whereIn()`, etc.). Category lookups happen on the raw
 * builder and the parent-chain walk uses a single in-memory map.
 */
final class TopCategoriesByPeriodQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return array<TopCategoryRow>
     */
    public function for(User $user, Period $period, int $limit = 5): array
    {
        $connection = $this->db->connection();

        $rows = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('amount_minor', '<', 0)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByRaw('SUM(-amount_minor) DESC')
            ->limit($limit)
            ->get([
                'category_id',
                $connection->raw('SUM(-amount_minor) AS spend_minor'),
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $total = 0;
        foreach ($rows as $row) {
            $total += self::toInt($row->spend_minor);
        }

        if ($total <= 0) {
            return [];
        }

        $categoryIds = [];
        foreach ($rows as $row) {
            $categoryIds[] = self::toInt($row->category_id);
        }

        $categoriesById = $this->loadCategories($categoryIds);

        $result = [];
        foreach ($rows as $row) {
            $categoryId = self::toInt($row->category_id);
            if (! isset($categoriesById[$categoryId])) {
                continue;
            }

            $spendMinor = self::toInt($row->spend_minor);
            $result[] = new TopCategoryRow(
                categoryId: $categoryId,
                name: $this->fullPath($categoryId, $categoriesById),
                spend: Money::ofMinor($spendMinor, 'EUR'),
                percentageOfTotal: $spendMinor / $total,
            );
        }

        return $result;
    }

    /**
     * Loads the requested categories plus the entire parent chain into a
     * single id-keyed map so `fullPath()` can resolve the breadcrumb
     * without per-row queries. SQLite handles small recursive `WHERE id IN`
     * fan-outs efficiently for the modest category tree expected in v1.
     *
     * @param  list<int>  $startingIds
     * @return array<int, stdClass>
     */
    private function loadCategories(array $startingIds): array
    {
        $connection = $this->db->connection();
        /** @var array<int, stdClass> $known */
        $known = [];

        $toFetch = array_values(array_unique($startingIds));
        while ($toFetch !== []) {
            $batch = $connection
                ->table('categories')
                ->whereIn('id', $toFetch)
                ->get(['id', 'parent_id', 'name']);

            $nextFetch = [];
            foreach ($batch as $row) {
                $id = self::toInt($row->id);
                $known[$id] = $row;
                $parentId = $row->parent_id === null ? null : self::toInt($row->parent_id);
                if ($parentId !== null && ! isset($known[$parentId])) {
                    $nextFetch[] = $parentId;
                }
            }
            $toFetch = array_values(array_unique($nextFetch));
        }

        return $known;
    }

    /**
     * @param  array<int, stdClass>  $byId
     */
    private function fullPath(int $categoryId, array $byId): string
    {
        $parts = [];
        $current = $categoryId;
        while (isset($byId[$current])) {
            $row = $byId[$current];
            array_unshift($parts, self::toString($row->name));
            $parentId = $row->parent_id === null ? null : self::toInt($row->parent_id);
            if ($parentId === null) {
                break;
            }
            $current = $parentId;
        }

        return implode(' / ', $parts);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
