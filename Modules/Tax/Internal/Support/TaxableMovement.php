<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;
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

    // The same question asked of rows instead of one row, so a candidate the
    // write refuses is never counted, offered, or reported as tagged: the
    // banner offered three siblings, wrote one, and said it had written three.
    public static function narrow(QueryBuilder $query, string $prefix = ''): QueryBuilder
    {
        return $query
            ->whereIn($prefix.'type', self::taggableTypes())
            ->where(static function (QueryBuilder $marks) use ($prefix): void {
                $marks->whereNull($prefix.'payment_type')
                    ->orWhere($prefix.'payment_type', '!=', PaymentType::Refund->value);
            });
    }

    // Derived by asking canCarryATag() about every case rather than listed, so
    // a type that changes its answer changes both spellings of the rule at once.
    /**
     * @return list<string>
     */
    private static function taggableTypes(): array
    {
        return array_values(array_filter(
            array_map(static fn (TransactionType $type): string => $type->value, TransactionType::cases()),
            static fn (string $type): bool => self::canCarryATag($type, null),
        ));
    }

    // The same two marks the rollups read a return by: the payment-type
    // detector writes one and a reader labelling by hand writes the other.
    private static function isReturn(mixed $type, mixed $paymentType): bool
    {
        return $type === TransactionType::Refund->value
            || $paymentType === PaymentType::Refund->value;
    }
}
