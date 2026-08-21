<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Enums;

// The vocabulary of raw_payload['chain_hints'][]['hint_type'], which Receipts
// writes and Chains reads. Distinct from Chains' own ChainLinkKind: Unknown has
// no chain_links.kind counterpart, and two kinds are not hints at all.
enum ChainHintType: string
{
    case FundedByCard = 'funded_by_card';

    case RefundOf = 'refund_of';

    case Unknown = 'unknown';
}
