<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The DB-layer BEFORE INSERT/UPDATE triggers reject any value outside this
// set regardless of write path; this enum is the code-side source of truth
// the write paths, the split rules and the direction derivation read from.
enum TransactionType: string
{
    case Expense = 'expense';

    case Income = 'income';

    case TransferOut = 'transfer_out';

    case TransferIn = 'transfer_in';

    case Fee = 'fee';

    case Refund = 'refund';

    case Adjustment = 'adjustment';

    // The transfer legs own the paired equal-and-opposite invariant via
    // pair_transaction_id.
    public function isTransfer(): bool
    {
        return $this === self::TransferOut || $this === self::TransferIn;
    }

    // A transfer can never carry category legs — SaveTransactionSplit and the
    // reclassify auto-unsplit coordination both depend on that.
    public function isSplittable(): bool
    {
        return ! $this->isTransfer();
    }

    public function direction(): Direction
    {
        return match ($this) {
            self::Income, self::TransferIn, self::Refund => Direction::Income,
            default => Direction::Expense,
        };
    }

    /** @return list<string> */
    public static function transferValues(): array
    {
        return [self::TransferOut->value, self::TransferIn->value];
    }
}
