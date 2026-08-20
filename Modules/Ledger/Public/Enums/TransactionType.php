<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The seven kinds of transaction. The DB-layer BEFORE INSERT/UPDATE triggers
// reject any value outside this set regardless of write path, and this enum
// is the code-side source of truth the write paths, the split rules and the
// direction derivation all read from.
enum TransactionType: string
{
    case Expense = 'expense';

    case Income = 'income';

    case TransferOut = 'transfer_out';

    case TransferIn = 'transfer_in';

    case Fee = 'fee';

    case Refund = 'refund';

    case Adjustment = 'adjustment';

    // transfer_out/transfer_in own the paired equal-and-opposite invariant via
    // pair_transaction_id; they are the only non-splittable types.
    public function isTransfer(): bool
    {
        return $this === self::TransferOut || $this === self::TransferIn;
    }

    // A transfer can never carry category legs (SaveTransactionSplit and the
    // reclassify auto-unsplit coordination depend on this), so "splittable" is
    // exactly "not a transfer".
    public function isSplittable(): bool
    {
        return ! $this->isTransfer();
    }

    // Money in vs money out: income, transfer_in and refund are inflows;
    // everything else is an outflow.
    public function direction(): Direction
    {
        return match ($this) {
            self::Income, self::TransferIn, self::Refund => Direction::Income,
            default => Direction::Expense,
        };
    }

    // The two transfer legs, for `whereIn('type', …)` filters that select
    // both sides of a transfer pair.
    /** @return list<string> */
    public static function transferValues(): array
    {
        return [self::TransferOut->value, self::TransferIn->value];
    }
}
