<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultRow;
use stdClass;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final class CategorySpendQuery
{
    use CoercesScalars;

    // SQL GROUP BY category_id already returns one NULL-keyed group; this
    // just gives it a non-colliding array key distinct from any real
    // (positive) autoincrement category id.
    private const UNCATEGORIZED_SENTINEL = -1;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @return list<ReportResultRow>
     */
    public function forUserAndPeriod(
        User $user,
        Period $period,
        string $metric,
        string $currency,
        SpendQueryFilters $filters = new SpendQueryFilters,
    ): array {
        $connection = $this->db->connection();
        $types = self::metricTypes($metric);
        /** @var array<int, int> $map */
        $map = [];

        // 1. Unsplit parents AND "broken split" parents: a parent rolls up
        // via its own category_id whenever it has no split rows at all OR
        // its legs do not sum to its settled_amount_minor. Uncategorized
        // rows are included here under the sentinel key (see architecture doc).
        $unsplit = $connection->table('transactions as t')
            // Kept as one parenthesized group: unparenthesized, SQL's AND
            // binding tighter than OR would let the unscoped NOT EXISTS
            // half match every unsplit transaction for every user/period,
            // not just this query's own scope.
            ->whereRaw('(NOT EXISTS (SELECT 1 FROM transaction_splits AS ts WHERE ts.transaction_id = t.id) OR COALESCE((SELECT SUM(ts.settled_amount_minor) FROM transaction_splits AS ts WHERE ts.transaction_id = t.id), 0) <> t.settled_amount_minor)')
            ->where('t.user_id', $user->id)
            ->where('t.settled_currency', $currency)
            ->whereIn('t.type', $types)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            ->when($filters->accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.account_id', $filters->accountIds))
            ->when($filters->categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.category_id', $filters->categoryIds))
            ->when($filters->counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.counterparty_id', $filters->counterpartyIds))
            ->when($filters->amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(t.settled_amount_minor) >= ?', [$filters->amountMinMinor]))
            ->when($filters->amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(t.settled_amount_minor) <= ?', [$filters->amountMaxMinor]))
            ->when($filters->amountDirection === 'in', static fn (QueryBuilder $q): QueryBuilder => $q->where('t.settled_amount_minor', '>', 0))
            ->when($filters->amountDirection === 'out', static fn (QueryBuilder $q): QueryBuilder => $q->where('t.settled_amount_minor', '<', 0))
            ->groupBy('t.category_id')
            ->get(['t.category_id', $connection->raw(self::unsplitAmountExpr($metric).' AS amount_minor')]);

        foreach ($unsplit as $row) {
            /** @var stdClass $row */
            $key = $row->category_id === null ? self::UNCATEGORIZED_SENTINEL : self::toInt($row->category_id);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->amount_minor);
        }

        // 2. Split legs: same user/period/type predicate as the parent,
        // joined through the parent for user_id/posted_at/type (legs carry
        // neither). transaction_splits.category_id is required, so legs
        // never need the uncategorized sentinel.
        $legs = $connection->table('transaction_splits as ts')
            ->join('transactions as t', 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $user->id)
            ->where('ts.settled_currency', $currency)
            ->whereIn('t.type', $types)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            // Only attribute legs when the split is internally consistent
            // (legs sum to the parent). Broken splits fall back to the
            // parent category above; ignored here so the two branches
            // never double-count nor drop spend.
            ->whereRaw('(SELECT SUM(ts2.settled_amount_minor) FROM transaction_splits AS ts2 WHERE ts2.transaction_id = ts.transaction_id) = t.settled_amount_minor')
            ->when($filters->accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.account_id', $filters->accountIds))
            ->when($filters->categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('ts.category_id', $filters->categoryIds))
            ->when($filters->counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.counterparty_id', $filters->counterpartyIds))
            ->when($filters->amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(ts.settled_amount_minor) >= ?', [$filters->amountMinMinor]))
            ->when($filters->amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(ts.settled_amount_minor) <= ?', [$filters->amountMaxMinor]))
            ->when($filters->amountDirection === 'in', static fn (QueryBuilder $q): QueryBuilder => $q->where('ts.settled_amount_minor', '>', 0))
            ->when($filters->amountDirection === 'out', static fn (QueryBuilder $q): QueryBuilder => $q->where('ts.settled_amount_minor', '<', 0))
            ->groupBy('ts.category_id')
            ->get(['ts.category_id', $connection->raw(self::legAmountExpr($metric).' AS amount_minor')]);

        foreach ($legs as $row) {
            /** @var stdClass $row */
            $categoryId = self::toInt($row->category_id);
            $map[$categoryId] = ($map[$categoryId] ?? 0) + self::toInt($row->amount_minor);
        }

        if ($map === []) {
            return [];
        }

        $resultCategoryIds = array_values(array_filter(array_keys($map), static fn (int $id): bool => $id !== self::UNCATEGORIZED_SENTINEL));
        $categoriesById = $this->loadCategories($resultCategoryIds, $user->id);

        $result = [];
        foreach ($map as $key => $amountMinor) {
            if ($key === self::UNCATEGORIZED_SENTINEL) {
                $result[] = new ReportResultRow(
                    groupKey: null,
                    groupLabel: Lang::get('reports::builder.uncategorized'),
                    amountMinor: $amountMinor,
                    currency: $currency,
                );

                continue;
            }

            $label = isset($categoriesById[$key])
                ? $this->fullPath($key, $categoriesById)
                : Lang::get('reports::builder.uncategorized');
            $result[] = new ReportResultRow(groupKey: $key, groupLabel: $label, amountMinor: $amountMinor, currency: $currency);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function metricTypes(string $metric): array
    {
        return match ($metric) {
            'spend' => ['expense'],
            'income' => ['income'],
            'net' => ['expense', 'income'],
            default => throw new InvalidArgumentException("Unknown report metric: {$metric}"),
        };
    }

    // Two separate literal-string-returning methods (t./ts. prefix) rather
    // than one parameterized helper: Connection::raw() requires a
    // literal-string argument, which a runtime string-interpolated column
    // name would not satisfy under strict PHPStan analysis.
    /**
     * @return literal-string
     */
    private static function unsplitAmountExpr(string $metric): string
    {
        return match ($metric) {
            'spend' => 'SUM(-t.settled_amount_minor)',
            'income', 'net' => 'SUM(t.settled_amount_minor)',
            default => throw new InvalidArgumentException("Unknown report metric: {$metric}"),
        };
    }

    /**
     * @return literal-string
     */
    private static function legAmountExpr(string $metric): string
    {
        return match ($metric) {
            'spend' => 'SUM(-ts.settled_amount_minor)',
            'income', 'net' => 'SUM(ts.settled_amount_minor)',
            default => throw new InvalidArgumentException("Unknown report metric: {$metric}"),
        };
    }

    /**
     * @param  list<int>  $startingIds
     * @return array<int, stdClass>
     */
    private function loadCategories(array $startingIds, int $userId): array
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
                ->get(['id', 'parent_id', 'name']);

            $nextFetch = [];
            foreach ($batch as $row) {
                /** @var stdClass $row */
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
     * @param  array<int, stdClass>  $byId
     */
    private function fullPath(int $categoryId, array $byId): string
    {
        $maxDepth = 16;
        $parts = [];
        $visited = [];
        $current = $categoryId;
        $depth = 0;

        while (isset($byId[$current]) && ! isset($visited[$current]) && $depth < $maxDepth) {
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
