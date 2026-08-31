<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Illuminate\Database\Query\Builder;

// "Is this split?" is answered by leg-row presence everywhere, and
// SaveTransactionSplit never sets the parent's category_id. So a split parent
// keeps NULL forever, and the surfaces that count uncategorized work by that
// column alone reported it outstanding though its legs categorize it in full.
final class SplitLegs
{
    public static function excludeParents(Builder $query, string $transactionsTable = 'transactions'): Builder
    {
        return $query->whereNotExists(static function (Builder $legs) use ($transactionsTable): void {
            $legs->from('transaction_splits')
                ->selectRaw('1')
                ->whereColumn('transaction_splits.transaction_id', $transactionsTable.'.id');
        });
    }
}
