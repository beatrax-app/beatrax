<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The kind of account a row represents: a plain `bank` account, an `asn`
// current account, an `ics_card` credit card, or a `paypal` wallet. The
// column stays string; this enum is the one canonical spelling callers map
// through.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
enum AccountKind: string
{
    case Bank = 'bank';

    case Asn = 'asn';

    case IcsCard = 'ics_card';

    case Paypal = 'paypal';
}
