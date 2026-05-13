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
 * integer `SUM(-settled_amount_minor)` so the database returns money totals
 * as integers — Money is composed at the DTO boundary (no float arithmetic
 * anywhere on the hot path). The aggregation is scoped to a single
 * `settled_currency` so multi-currency users see a single-currency panel
 * rather than a silently-summed mix.
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
    public const DEFAULT_DISPLAY_CURRENCY = 'EUR';

    private const MAX_PARENT_DEPTH = 16;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return array<TopCategoryRow>
     */
    public function for(User $user, Period $period, string $displayCurrency = self::DEFAULT_DISPLAY_CURRENCY, int $limit = 5): array
    {
        $connection = $this->db->connection();

        $rows = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('settled_currency', $displayCurrency)
            ->where('settled_amount_minor', '<', 0)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByRaw('SUM(-settled_amount_minor) DESC')
            ->limit($limit)
            ->get([
                'category_id',
                $connection->raw('SUM(-settled_amount_minor) AS spend_minor'),
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

        $categoriesById = $this->loadCategories($categoryIds, $user->id);

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
                spend: Money::ofMinor($spendMinor, $displayCurrency),
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
     * The visibility predicate (`user_id IS NULL OR user_id = $userId`)
     * applies to every level of the walk. A `parent_id` that points
     * cross-tenant (corrupt import, future cross-user share, manual SQL
     * edit) terminates the chain at the filtered-out parent rather than
     * leaking the foreign user's category name into the breadcrumb.
     *
     * The `$attempted` set tracks ids that have already been queried
     * (regardless of whether they came back from the database) so the
     * grandparent of a filtered-out parent is never enqueued. Without
     * this guard, every visibility miss costs an extra empty SELECT on
     * `categories` before the loop terminates.
     *
     * @param  list<int>  $startingIds
     * @return array<int, stdClass>
     */
    private function loadCategories(array $startingIds, int $userId): array
    {
        $connection = $this->db->connection();
        /** @var array<int, stdClass> $known */
        $known = [];
        /** @var array<int, true> $attempted */
        $attempted = [];

        $toFetch = array_values(array_unique($startingIds));
        while ($toFetch !== []) {
            foreach ($toFetch as $id) {
                $attempted[$id] = true;
            }

            $batch = $connection
                ->table('categories')
                ->whereIn('id', $toFetch)
                ->where(static function ($q) use ($userId): void {
                    $q->whereNull('user_id')->orWhere('user_id', $userId);
                })
                ->get(['id', 'parent_id', 'name']);

            $nextFetch = [];
            foreach ($batch as $row) {
                $id = self::toInt($row->id);
                $known[$id] = $row;
                $parentId = $row->parent_id === null ? null : self::toInt($row->parent_id);
                if ($parentId !== null && ! isset($known[$parentId]) && ! isset($attempted[$parentId])) {
                    $nextFetch[] = $parentId;
                }
            }
            $toFetch = array_values(array_unique($nextFetch));
        }

        return $known;
    }

    /**
     * Walks the parent chain to build the breadcrumb path. A `visited` set
     * and a hard depth cap guard against accidental parent cycles in the
     * `categories` table — Eloquent does not enforce acyclicity, so any bad
     * data (external import, manual edit) would otherwise spin this loop
     * forever.
     *
     * @param  array<int, stdClass>  $byId
     */
    private function fullPath(int $categoryId, array $byId): string
    {
        $parts = [];
        $visited = [];
        $current = $categoryId;
        $depth = 0;

        while (isset($byId[$current]) && ! isset($visited[$current]) && $depth < self::MAX_PARENT_DEPTH) {
            $visited[$current] = true;
            $row = $byId[$current];
            array_unshift($parts, self::toString($row->name));
            $parentId = $row->parent_id === null ? null : self::toInt($row->parent_id);
            if ($parentId === null) {
                break;
            }
            $current = $parentId;
            $depth++;
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
