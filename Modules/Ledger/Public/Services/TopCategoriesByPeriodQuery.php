<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * Top-N spending categories for a given user + period window.
 *
 * Delegates the aggregation to `SpendByCategoryQuery::forUserAndPeriod()`
 * (Phase 13.1 / Req 4), the shared legs ∪ unsplit-parents read model — a
 * split transaction's legs count individually and its parent row is
 * excluded, so this panel (and, by delegation, the dashboard) never
 * double-counts a split. Money totals stay integer end-to-end; Money is
 * composed at the DTO boundary (no float arithmetic anywhere on the hot
 * path). The aggregation is scoped to a single `settled_currency` so
 * multi-currency users see a single-currency panel rather than a
 * silently-summed mix. The shared service returns an unordered map, so the
 * DESC-by-spend ordering + limit are re-applied here in PHP.
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

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SpendByCategoryQuery $spendByCategory,
    ) {}

    /**
     * @return array<TopCategoryRow>
     */
    public function for(User $user, Period $period, string $displayCurrency = self::DEFAULT_DISPLAY_CURRENCY, int $limit = 5): array
    {
        // Delegates to the shared legs ∪ unsplit-parents read model (Req 4 /
        // D-02) — split transactions count their legs, never the parent row.
        // The service returns an unordered map, so the DESC-by-spend +
        // limit ordering the old single query did in SQL is re-applied here
        // in PHP.
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
        $categoriesById = $this->loadCategories($categoryIds, $user->id);

        $result = [];
        foreach ($spendByCategoryId as $categoryId => $spendMinor) {
            if (! isset($categoriesById[$categoryId])) {
                continue;
            }

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
                ->where(static function (QueryBuilder $q) use ($userId): void {
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
