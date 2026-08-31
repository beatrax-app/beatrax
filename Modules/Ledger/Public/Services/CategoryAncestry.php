<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\Support\CategoryPathName;
use stdClass;

// The breadcrumb behind a category id: one batched walk up the parent chain,
// then a path resolved off the map that walk returns. More than one module
// renders that breadcrumb, and a second copy of the walk is a second copy of
// the visibility predicate below to keep right.
final readonly class CategoryAncestry
{
    use CoercesScalars;

    private const int MAX_PARENT_DEPTH = 16;

    public function __construct(private DatabaseManager $db) {}

    // The visibility predicate applies at every level of the walk: a parent_id
    // pointing cross-tenant ends the chain at the filtered-out parent rather
    // than leaking a foreign name. $attempted holds every id already queried,
    // so the grandparent of a filtered-out parent is never re-enqueued.
    /**
     * @param  list<int>  $startingIds
     * @return array<int, stdClass>
     */
    public function load(array $startingIds, int $userId): array
    {
        if ($startingIds === []) {
            return [];
        }

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
                ->get(['id', 'parent_id', ...CategoryDisplayName::bareColumns()]);

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
    public function fullPath(int $categoryId, array $byId): string
    {
        $parts = [];
        $visited = [];
        $current = $categoryId;
        $depth = 0;

        while (isset($byId[$current]) && ! isset($visited[$current]) && $depth < self::MAX_PARENT_DEPTH) {
            $visited[$current] = true;
            $row = $byId[$current];
            array_unshift($parts, CategoryDisplayName::fromRow($row) ?? '');
            $parentId = $row->parent_id === null ? null : self::toInt($row->parent_id);
            if ($parentId === null) {
                break;
            }
            $current = $parentId;
            $depth++;
        }

        return CategoryPathName::fromParts($parts);
    }
}
