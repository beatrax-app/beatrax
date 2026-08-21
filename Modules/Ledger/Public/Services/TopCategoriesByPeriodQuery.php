<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#topcategoriesbyperiodquery--breadcrumb-category-tree-walk
 */
final class TopCategoriesByPeriodQuery
{
    use CoercesScalars;

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
        // The shared service returns an unordered map, so DESC-by-spend
        // ordering + limit are re-applied here in PHP.
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

    // See the linked architecture page for the visibility predicate and
    // the $attempted-set optimization this walk relies on.
    /**
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

    // A visited set + hard depth cap guard against accidental parent
    // cycles — Eloquent does not enforce acyclicity on categories.
    /**
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
}
