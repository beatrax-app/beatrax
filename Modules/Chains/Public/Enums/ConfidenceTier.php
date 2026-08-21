<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// Derived for display from (state, resolver, confidence) and never stored:
// Deterministic has no chain_links.state counterpart. The capitalised backing
// value is the badge label chain-node.blade.php renders verbatim, which is the
// only reason this vocabulary is spelled differently from ChainLinkState.
enum ConfidenceTier: string
{
    case Deterministic = 'Deterministic';

    case Confirmed = 'Confirmed';

    case Candidate = 'Candidate';
}
