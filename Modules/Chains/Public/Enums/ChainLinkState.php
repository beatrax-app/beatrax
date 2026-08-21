<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// A resolver only ever proposes a Candidate; Confirmed and Rejected come from
// the user, or from a high-confidence auto-promotion. The column stays a string
// (a trigger enforces the vocabulary) and this enum is its canonical spelling.
enum ChainLinkState: string
{
    case Candidate = 'candidate';

    case Confirmed = 'confirmed';

    case Rejected = 'rejected';
}
