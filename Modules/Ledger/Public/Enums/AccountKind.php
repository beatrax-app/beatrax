<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The generic type of account a row represents: a `bank` account, an
// `ics_card` credit card, a `paypal` wallet, a `cash` account, or a
// `paypal_funding` pseudo-account. Bank identity (ASN etc.) is NOT a kind —
// it lives in the import format and the OpenBanking institution.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
enum AccountKind: string
{
    case Bank = 'bank';

    case IcsCard = 'ics_card';

    case Paypal = 'paypal';

    case Cash = 'cash';

    case PaypalFunding = 'paypal_funding';
}
