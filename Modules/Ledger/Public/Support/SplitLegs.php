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
    // A leg belongs to whoever owns its parent, which is where that fact is
    // stored once. `transaction_splits.user_id` is a nullable copy, and reading
    // it gives two wrong answers: matching it drops a leg carrying none, and
    // skipping it admits one hanging off somebody else's transaction.
    public static function ownedBy(Builder $query, int $userId, string $legsTable = 'transaction_splits'): Builder
    {
        return $query->whereExists(static function (Builder $owner) use ($userId, $legsTable): void {
            // Aliased, because a caller may already have `transactions` joined
            // in and an unaliased correlation would bind to that one instead.
            $owner->from('transactions as split_leg_owner')
                ->selectRaw('1')
                ->whereColumn('split_leg_owner.id', $legsTable.'.transaction_id')
                ->where('split_leg_owner.user_id', $userId);
        });
    }

    public static function excludeParents(Builder $query, string $transactionsTable = 'transactions'): Builder
    {
        return $query->whereNotExists(static function (Builder $legs) use ($transactionsTable): void {
            $legs->from('transaction_splits')
                ->selectRaw('1')
                ->whereColumn('transaction_splits.transaction_id', $transactionsTable.'.id');
        });
    }
}
