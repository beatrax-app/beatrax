<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Support;

use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Enums\TransactionType;

// The year total takes abs() of every tagged row that is not income, so a row
// that can carry no deduction still lands in the figure as one. Judged here,
// on the way in, rather than by the reader noticing a total that is too big.
/**
 * @link ../../../../.docs/features/tax/tag-write-contract.md#which-rows-may-carry-a-tag
 */
final class TaxableMovement
{
    // Money that crossed between the reader and somebody else, and stayed
    // crossed. A transfer is the reader's own money moving and an adjustment
    // reconciles against nobody; a refund crossed back, and abs() would file
    // the money returned as money spent.
    public static function canCarryATag(mixed $type, mixed $paymentType): bool
    {
        if (! TransactionType::isExternalMovementOf($type)) {
            return false;
        }

        return ! self::isReturn($type, $paymentType);
    }

    // The same two marks the rollups read a return by: the payment-type
    // detector writes one and a reader labelling by hand writes the other.
    private static function isReturn(mixed $type, mixed $paymentType): bool
    {
        return $type === TransactionType::Refund->value
            || $paymentType === PaymentType::Refund->value;
    }
}
