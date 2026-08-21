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
}
