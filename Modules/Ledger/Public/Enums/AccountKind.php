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

    case GooglePlay = 'google_play';

    // Rows on it restate a movement the paying account already carries:
    // paypal_funding routes a transfer posted on both accounts it sits
    // between, and a Play receipt names a charge the card or wallet was
    // debited for. Any total summing both accounts counts that money twice.
    /**
     * @link ../../../../.docs/features/ledger/architecture.md#accountkind--which-kinds-hold-money
     */
    public function mirrorsAnotherAccount(): bool
    {
        return match ($this) {
            self::PaypalFunding, self::GooglePlay => true,
            self::Bank, self::IcsCard, self::Paypal, self::Cash => false,
        };
    }

    // A card balance is what is OWED. Net worth subtracts it because a debt is
    // part of the position; a spendable line leaves it out because the money
    // to pay it still sits on the account that will settle it, and summing
    // both would subtract the settlement before it happened and again after.
    public function isLiability(): bool
    {
        return $this === self::IcsCard;
    }

    // Money the reader HOLDS — what a pot is carved out of and what a forward
    // balance line may sum. Narrower than the position by exactly the
    // liability, and narrower than the account list by the mirrors above.
    public function holdsSpendableBalance(): bool
    {
        return ! $this->mirrorsAnotherAccount() && ! $this->isLiability();
    }

    /**
     * @return list<string>
     */
    public static function spendableValues(): array
    {
        return self::valuesWhere(static fn (self $kind): bool => $kind->holdsSpendableBalance());
    }

    /**
     * @return list<string>
     */
    public static function mirrorValues(): array
    {
        return self::valuesWhere(static fn (self $kind): bool => $kind->mirrorsAnotherAccount());
    }

    /**
     * @param  callable(self): bool  $predicate
     * @return list<string>
     */
    private static function valuesWhere(callable $predicate): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(self::cases(), $predicate),
        ));
    }
}
