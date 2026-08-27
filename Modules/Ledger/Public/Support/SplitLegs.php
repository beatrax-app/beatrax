<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Illuminate\Database\Query\Builder;

// Everything in this codebase answers "is this transaction split?" by leg-row
// presence, and SaveTransactionSplit never sets the parent's own category_id.
// So a transaction split out of an uncategorized state keeps category_id NULL
// forever, and the three surfaces that count uncategorized work by that column
// alone reported it as outstanding even though its legs categorize it in full.
// Assigning a category to clear the row writes a value no read surface uses,
// and stamps manual provenance that locks the row out of every future rule.
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
