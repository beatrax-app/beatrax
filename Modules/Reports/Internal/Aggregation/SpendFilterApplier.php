<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Ledger\Public\Enums\AmountDirection;

final class SpendFilterApplier
{
    // Applied against unqualified columns of the unaliased `transactions`
    // table, which the category branch names to reach its legs. The
    // CategorySpendQuery keeps its own t./ts.-qualified variant inline.
    public function apply(QueryBuilder $query, SpendQueryFilters $filters): QueryBuilder
    {
        return $query
            ->when($filters->accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('account_id', $filters->accountIds))
            ->when($filters->categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => CategoryAttribution::apply($q, $filters->categoryIds))
            ->when($filters->counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('counterparty_id', $filters->counterpartyIds))
            ->when($filters->amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) >= ?', [$filters->amountMinMinor]))
            ->when($filters->amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) <= ?', [$filters->amountMaxMinor]))
            ->when($filters->amountDirection === AmountDirection::In->value, static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '>', 0))
            ->when($filters->amountDirection === AmountDirection::Out->value, static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '<', 0));
    }

    // Selecting a transaction and counting it are one decision, so they are
    // read from one place: a category filter attributes a split parent by its
    // legs above, and the figure has to be the leg's amount to match.
    /**
     * @return literal-string
     */
    public function amountExpr(ReportMetric $metric, SpendQueryFilters $filters): string
    {
        return $filters->categoryIds === []
            ? $metric->sumExpr()
            : $metric->sumExpr(CategoryAttribution::LEG_OR_PARENT);
    }
}
