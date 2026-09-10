<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

// Splitting a transaction is precisely how part of it is attributed to a
// category, so a category filter selects a transaction by its legs as readily
// as by its own column -- and what it then contributes is the leg's amount,
// never nothing and never the whole parent.
final class CategoryAttribution
{
    // The amount source a caller names, and the SQL it names. Spelled here
    // rather than at ReportMetric::sumExpr(), which would then carry a join
    // alias this class owns.
    public const string LEG_OR_PARENT = 'leg_or_parent';

    public const string LEG_OR_PARENT_MINOR = 'COALESCE('.self::JOIN_ALIAS.'.matched_minor, transactions.settled_amount_minor)';

    private const string JOIN_ALIAS = 'matched_legs';

    // The two cases the money stays on the parent: no legs at all, or legs that
    // do not sum to it. The same predicate CategorySpendQuery's first pass rolls
    // up, so the two can never disagree about which row holds the amount.
    private const string PARENT_HOLDS_THE_AMOUNT = '(NOT EXISTS (SELECT 1 FROM transaction_splits AS leg WHERE leg.transaction_id = transactions.id) OR COALESCE((SELECT SUM(leg.settled_amount_minor) FROM transaction_splits AS leg WHERE leg.transaction_id = transactions.id), 0) <> transactions.settled_amount_minor)';

    /**
     * @param  QueryBuilder  $query  scoped to the unaliased `transactions` table; the joined subquery exposes none of its column names, so the caller's own predicates stay unqualified
     * @param  list<int>  $categoryIds
     */
    public static function apply(QueryBuilder $query, array $categoryIds): QueryBuilder
    {
        $matched = $query->getConnection()
            ->table('transaction_splits')
            ->select('transaction_id')
            ->selectRaw('settled_currency AS leg_currency, SUM(settled_amount_minor) AS matched_minor')
            ->whereIn('category_id', $categoryIds)
            ->groupBy('transaction_id', 'settled_currency');

        return $query
            ->leftJoinSub($matched, self::JOIN_ALIAS, static function (JoinClause $join): void {
                // A leg is always written in its parent's currency, so matching
                // on it costs nothing and keeps a stray one out of a bucket it
                // is not money in. Legs hold the amount only when they sum to
                // the parent; a broken split falls back to the parent below.
                $join->on(self::JOIN_ALIAS.'.transaction_id', '=', 'transactions.id')
                    ->on(self::JOIN_ALIAS.'.leg_currency', '=', 'transactions.settled_currency')
                    ->whereRaw('NOT '.self::PARENT_HOLDS_THE_AMOUNT);
            })
            ->where(static function (QueryBuilder $filtered) use ($categoryIds): void {
                $filtered->whereNotNull(self::JOIN_ALIAS.'.transaction_id')
                    ->orWhere(static function (QueryBuilder $parent) use ($categoryIds): void {
                        $parent->whereRaw(self::PARENT_HOLDS_THE_AMOUNT)
                            ->whereIn('transactions.category_id', $categoryIds);
                    });
            });
    }
}
