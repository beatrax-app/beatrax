<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// The column stays a string (a trigger enforces the vocabulary); this enum is
// the one canonical spelling callers map through.
enum ChainLinkKind: string
{
    case PaypalFunding = 'paypal_funding';

    case IcsBulkSettle = 'ics_bulk_settle';

    case FundedByCardHint = 'funded_by_card_hint';

    case RefundOfHint = 'refund_of_hint';

    // Which endpoint is the settlement the other rows fan into. ics_bulk_settle
    // runs FROM the one bank payment TO each charge it settled; every other kind
    // runs from the payment TO the leg that funded it. Grouping on the wrong end
    // renders one card per charge, each claiming the whole settlement amount.
    public function settlementIsFromSide(): bool
    {
        return $this === self::IcsBulkSettle;
    }
}
