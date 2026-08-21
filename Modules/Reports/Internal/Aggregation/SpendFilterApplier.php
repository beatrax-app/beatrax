<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\Query\Builder as QueryBuilder;

final class SpendFilterApplier
{
    // Applied against unqualified transactions columns. The split-aware
    // CategorySpendQuery keeps its own t./ts.-qualified variant inline.
    public function apply(QueryBuilder $query, SpendQueryFilters $filters): QueryBuilder
    {
        return $query
            ->when($filters->accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('account_id', $filters->accountIds))
            ->when($filters->categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('category_id', $filters->categoryIds))
            ->when($filters->counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('counterparty_id', $filters->counterpartyIds))
            ->when($filters->amountMinMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) >= ?', [$filters->amountMinMinor]))
            ->when($filters->amountMaxMinor !== null, static fn (QueryBuilder $q): QueryBuilder => $q->whereRaw('ABS(settled_amount_minor) <= ?', [$filters->amountMaxMinor]))
            ->when($filters->amountDirection === 'in', static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '>', 0))
            ->when($filters->amountDirection === 'out', static fn (QueryBuilder $q): QueryBuilder => $q->where('settled_amount_minor', '<', 0));
    }
}
