<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

// transfer_in/transfer_out are excluded — they own the paired
// equal-and-opposite invariant via pair_transaction_id and can never
// carry category legs. SaveTransactionSplit, TransactionDetail, and
// the reclassify auto-unsplit coordination all consume this one list.
final class SplittableTypes
{
    /** @var list<string> */
    public const TYPES = ['expense', 'income', 'fee', 'refund', 'adjustment'];

    private function __construct() {}

    public static function contains(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }
}
