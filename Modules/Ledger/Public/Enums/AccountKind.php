<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Bank identity (ASN etc.) is NOT a kind — it lives in the import format
// and the OpenBanking institution.
enum AccountKind: string
{
    case Bank = 'bank';

    case IcsCard = 'ics_card';

    case Paypal = 'paypal';

    case Cash = 'cash';

    case PaypalFunding = 'paypal_funding';

    // A pot is a virtual sub-balance of money the reader HOLDS. A credit card
    // is a liability -- its balance is what is owed -- so allocating into one
    // makes the unallocated figure negative by construction, and the page then
    // tells the reader they over-allocated something they never had.
    public function holdsAllocatableBalance(): bool
    {
        return match ($this) {
            self::Bank, self::Cash, self::Paypal => true,
            self::IcsCard, self::PaypalFunding => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function allocatableValues(): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(self::cases(), static fn (self $kind): bool => $kind->holdsAllocatableBalance()),
        ));
    }
}
