<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

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

    // Whether the legs add up to their parent, which is what decides whether a
    // roll-up counts the parent or the legs -- asked in the parent's own
    // currency, because a leg priced elsewhere is not a slice of this amount
    // and its minor units added to the others make a figure in no currency.
    /**
     * @link ../../../../.docs/features/ledger/architecture.md#spendbycategoryquery--the-split-aware-spend-read-model
     *
     * @return literal-string
     */
    public static function addUpToTheParent(string $parentPrefix): string
    {
        return match ($parentPrefix) {
            't.' => '(COALESCE((SELECT SUM(split_leg_sum.settled_amount_minor) FROM transaction_splits AS split_leg_sum'
                .' WHERE split_leg_sum.transaction_id = t.id AND split_leg_sum.settled_currency = t.settled_currency), 0)'
                .' = t.settled_amount_minor AND NOT EXISTS (SELECT 1 FROM transaction_splits AS split_leg_other'
                .' WHERE split_leg_other.transaction_id = t.id AND split_leg_other.settled_currency <> t.settled_currency))',
            'transactions.' => '(COALESCE((SELECT SUM(split_leg_sum.settled_amount_minor) FROM transaction_splits AS split_leg_sum'
                .' WHERE split_leg_sum.transaction_id = transactions.id AND split_leg_sum.settled_currency = transactions.settled_currency), 0)'
                .' = transactions.settled_amount_minor AND NOT EXISTS (SELECT 1 FROM transaction_splits AS split_leg_other'
                .' WHERE split_leg_other.transaction_id = transactions.id AND split_leg_other.settled_currency <> transactions.settled_currency))',
            default => throw new InvalidArgumentException(sprintf('Unknown parent prefix: %s', $parentPrefix)),
        };
    }

    // The complement, for the roll-up's other branch: the money stays on the
    // parent when there are no legs at all, and when the legs it has do not add
    // up to it. One parenthesised group, because AND binds tighter than OR.
    /**
     * @return literal-string
     */
    public static function parentHoldsTheAmount(string $parentPrefix): string
    {
        return match ($parentPrefix) {
            't.' => '(NOT EXISTS (SELECT 1 FROM transaction_splits AS split_leg_any WHERE split_leg_any.transaction_id = t.id)'
                .' OR NOT '.self::addUpToTheParent('t.').')',
            'transactions.' => '(NOT EXISTS (SELECT 1 FROM transaction_splits AS split_leg_any WHERE split_leg_any.transaction_id = transactions.id)'
                .' OR NOT '.self::addUpToTheParent('transactions.').')',
            default => throw new InvalidArgumentException(sprintf('Unknown parent prefix: %s', $parentPrefix)),
        };
    }
}
