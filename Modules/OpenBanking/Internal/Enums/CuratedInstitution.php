<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Enums;

// The banks the wizard offers by name. An institution outside this list stays
// first-class through the free-text path, so this is a shortlist, never a
// whitelist -- what it owns is the id-to-name table three call sites derived
// separately while open_banking_connections.bank_display_name went unwritten.
enum CuratedInstitution: string
{
    case Asn = 'ASNBNL21';

    case Sns = 'SNSBNL21';

    public function displayName(): string
    {
        return match ($this) {
            self::Asn => 'ASN Bank',
            self::Sns => 'SNS (de Volksbank)',
        };
    }

    public function choice(): BankChoice
    {
        return match ($this) {
            self::Asn => BankChoice::Asn,
            self::Sns => BankChoice::Sns,
        };
    }
}
