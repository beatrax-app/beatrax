<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// The kind of settlement a chain_links row records: a PayPal funding leg,
// a bulk iDEAL settlement, or one of the two card-hint links. The column
// stays string; this enum is the one canonical spelling callers map
// through.
enum ChainLinkKind: string
{
    case PaypalFunding = 'paypal_funding';

    case IcsBulkSettle = 'ics_bulk_settle';

    case FundedByCardHint = 'funded_by_card_hint';

    case RefundOfHint = 'refund_of_hint';
}
