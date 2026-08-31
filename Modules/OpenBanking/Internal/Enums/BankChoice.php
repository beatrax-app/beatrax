<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Enums;

// Which radio the wizard's bank step has selected. Distinct from
// CuratedInstitution because Other names no institution at all -- the reader
// types the id themselves.
enum BankChoice: string
{
    case Asn = 'asn';

    case Sns = 'sns';

    case Other = 'other';

    public function institution(): ?CuratedInstitution
    {
        return match ($this) {
            self::Asn => CuratedInstitution::Asn,
            self::Sns => CuratedInstitution::Sns,
            self::Other => null,
        };
    }
}
