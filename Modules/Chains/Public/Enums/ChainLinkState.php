<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// The review state of a chain_links row: a resolver proposes a
// `candidate`, the user (or a high-confidence auto-resolve) makes it
// `confirmed`, or rejects it. The column stays string; this enum is the
// one canonical spelling every caller maps through.
enum ChainLinkState: string
{
    case Candidate = 'candidate';

    case Confirmed = 'confirmed';

    case Rejected = 'rejected';
}
