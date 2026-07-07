<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Dto\ReportResultRow;
use stdClass;

/**
 * Category-dimension aggregation for the Reports builder's spend/income/net
 * metrics (Req 2/3) — the ONE place the split-leg-aware `legs ∪
 * unsplit-parents` join (Phase 13.1 / D-02, structurally identical to
 * `Modules\Ledger\Public\Services\SpendByCategoryQuery`) is combined with
 * the canonical TYPE-BASED definition of spend/income/net
 * (`transactions.type IN ('expense','income')`,
 * `ThisPeriodAtAGlanceQuery::incomeForPeriod()`'s definition —
 * 999.6-RESEARCH.md Pitfall 1).
 *
 * This is a deliberately NEW class, not a `SpendByCategoryQuery` reuse:
 * `SpendByCategoryQuery` filters `settled_amount_minor < 0` (sign-based,
 * includes transfers/fees/refunds), which would make the category
 * dimension's total disagree with the counterparty/account/time_bucket
 * dimensions' type-based totals for the identical report — the exact bug
 * class `cross_dimension_total_consistency` (Plan 06's
 * `ReportAggregatorMetricsTest`) exists to catch.
 *
 * "Is this transaction split?" is answered ONLY by leg-row presence
 * (`whereNotExists`/join against `transaction_splits`), NEVER by
 * `category_id` nullity — mirrors `SpendByCategoryQuery`'s WR-03
 * broken-split fallback: a parent whose legs do not sum to its own
 * `settled_amount_minor` rolls up via its OWN `category_id` instead of
 * silently attributing partial legs.
 *
 * Uncategorized rows (`category_id IS NULL`) are INCLUDED as their own
 * "Uncategorized" group — unlike `SpendByCategoryQuery::forUserAndPeriod()`,
 * which defaults to excluding them. Excluding uncategorized rows here would
 * make the category dimension's grand total systematically lower than the
 * other three dimensions' totals (which have no category filter at all),
 * breaking cross-dimension consistency for any user with even one
 * uncategorized expense/income row.
 */
final class CategorySpendQuery
{
    /**
     * In-memory sentinel key for the "no category" bucket — SQL `GROUP BY
     * category_id` already returns one NULL-keyed group, this just gives it
     * a non-colliding array key distinct from any real (positive)
     * autoincrement category id.
     */
    private const UNCATEGORIZED_SENTINEL = -1;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @param  list<int>  $accountIds  T-999.6-06/14: restrict to these account ids (empty = no restriction). Applied ALONGSIDE the existing `where('user_id', ...)` guard below, so a foreign id can only ever narrow this user's own result to nothing — never widen it to another user's rows.
     * @param  list<int>  $categoryIds  restrict to these category ids (empty = no restriction)
     * @param  list<int>  $counterpartyIds  restrict to these counterparty ids (empty = no restriction)
     * @param  ?int  $amountMinMinor  restrict to rows whose ABS(settled_amount_minor) >= this (empty = no restriction)
     * @param  ?int  $amountMaxMinor  restrict to rows whose ABS(settled_amount_minor) <= this (empty = no restriction)
     * @param  string  $amountDirection  'in' | 'out' | 'both' — restricts to settled_amount_minor > 0 / < 0 / no restriction
     * @return list<ReportResultRow>
     */
    public function forUserAndPeriod(
        User $user,
        Period $period,
        string $metric,
        string $currency,
        array $accountIds = [],
        array $categoryIds = [],
        array $counterpartyIds = [],
        ?int $amountMinMinor = null,
        ?int $amountMaxMinor = null,
        string $amountDirection = 'both',
    ): array {
        $connection = $this->db->connection();
        $types = self::metricTypes($metric);
        /** @var array<int, int> $map */
        $map = [];

        // 1. Unsplit parents AND "broken split" parents (WR-03 fail-safe,
        // mirrored from SpendByCategoryQuery): a parent rolls up via its OWN
        // category_id whenever it has NO split rows at all (WR-01: a
        // genuinely unsplit $0.00 transaction has no legs, so it must be
        // captured on the "no legs exist" branch alone — the amount
        // comparison below it is false for that case, `0 <> 0`, which used
        // to silently drop it from every bucket) OR its legs do NOT sum to
        // its settled_amount_minor. Uncategorized (category_id IS NULL)
        // rows are INCLUDED here (see class docblock) under the sentinel
        // key.
        $unsplit = $connection->table('transactions as t')
            // Parenthesized as a single group (CR-01-adjacent SQL-precedence
            // fix): a bare `A OR B` raw fragment AND-ed with the predicates
            // below it would parse as `A OR (B AND ...)` — SQL's AND binds
            // tighter than OR — letting the unscoped NOT EXISTS half match
            // every unsplit transaction for EVERY user/period/type, not just
            // this query's own scope. Must stay wrapped in parens.
            ->whereRaw('(NOT EXISTS (SELECT 1 FROM transaction_splits AS ts WHERE ts.transaction_id = t.id) OR COALESCE((SELECT SUM(ts.settled_amount_minor) FROM transaction_splits AS ts WHERE ts.transaction_id = t.id), 0) <> t.settled_amount_minor)')
            ->where('t.user_id', $user->id)
            ->where('t.settled_currency', $currency)
            ->whereIn('t.type', $types)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            ->when($accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.account_id', $accountIds))
            ->when($categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.category_id', $categoryIds))
            ->when($counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.counterparty_id', $counterpartyIds))
            ->when($amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(t.settled_amount_minor) >= ?', [$amountMinMinor]))
            ->when($amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(t.settled_amount_minor) <= ?', [$amountMaxMinor]))
            ->when($amountDirection === 'in', static fn (QueryBuilder $q): QueryBuilder => $q->where('t.settled_amount_minor', '>', 0))
            ->when($amountDirection === 'out', static fn (QueryBuilder $q): QueryBuilder => $q->where('t.settled_amount_minor', '<', 0))
            ->groupBy('t.category_id')
            ->get(['t.category_id', $connection->raw(self::unsplitAmountExpr($metric).' AS amount_minor')]);

        foreach ($unsplit as $row) {
            /** @var stdClass $row */
            $key = $row->category_id === null ? self::UNCATEGORIZED_SENTINEL : self::toInt($row->category_id);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->amount_minor);
        }

        // 2. Split legs: same user/period/type predicate as the parent,
        // joined through the parent for user_id/posted_at/type (legs carry
        // neither). transaction_splits.category_id is REQUIRED (D-11 /
        // 13.1-01) so legs never need the uncategorized sentinel.
        $legs = $connection->table('transaction_splits as ts')
            ->join('transactions as t', 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $user->id)
            ->where('ts.settled_currency', $currency)
            ->whereIn('t.type', $types)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            // WR-03: only attribute legs when the split is internally
            // consistent (legs sum to the parent). Broken splits fall back
            // to the parent category above; ignored here so the two
            // branches never double-count nor drop spend.
            ->whereRaw('(SELECT SUM(ts2.settled_amount_minor) FROM transaction_splits AS ts2 WHERE ts2.transaction_id = ts.transaction_id) = t.settled_amount_minor')
            ->when($accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.account_id', $accountIds))
            ->when($categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('ts.category_id', $categoryIds))
            ->when($counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('t.counterparty_id', $counterpartyIds))
            ->when($amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(ts.settled_amount_minor) >= ?', [$amountMinMinor]))
            ->when($amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(ts.settled_amount_minor) <= ?', [$amountMaxMinor]))
            ->when($amountDirection === 'in', static fn (QueryBuilder $q): QueryBuilder => $q->where('ts.settled_amount_minor', '>', 0))
            ->when($amountDirection === 'out', static fn (QueryBuilder $q): QueryBuilder => $q->where('ts.settled_amount_minor', '<', 0))
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
                $result[] = new ReportResultRow(groupKey: null, groupLabel: 'Uncategorized', amountMinor: $amountMinor, currency: $currency);

                continue;
            }

            $label = isset($categoriesById[$key]) ? $this->fullPath($key, $categoriesById) : 'Uncategorized';
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

    /**
     * 'spend' negates the (always-negative) expense amount to a positive
     * minor total; 'income'/'net' sum the signed column directly — an
     * expense row's negative contribution and an income row's positive
     * contribution net out correctly for 'net' with zero extra logic.
     *
     * Two separate literal-string-returning methods (t./ts. prefix) rather
     * than one parameterized helper — `Connection::raw()` requires a
     * `literal-string` argument (PHPStan L10), which a runtime
     * string-interpolated column name would not satisfy.
     *
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
     * Loads the requested categories plus their entire parent chain into a
     * single id-keyed map, mirroring
     * `TopCategoriesByPeriodQuery::loadCategories()` (not shared as a Public
     * helper — every read model in this codebase repeats its own small
     * query helpers rather than sharing a trait, 999.6-PATTERNS.md).
     *
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
            array_unshift($parts, self::toStr($row->name));
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

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
