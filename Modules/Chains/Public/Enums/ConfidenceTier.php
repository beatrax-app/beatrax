<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// Derived for display from (state, resolver, confidence) and never stored:
// Deterministic has no chain_links.state counterpart. The backing value is a
// key fragment, not a label — spelled as English prose it went verbatim onto a
// badge in 25 locales that had translated the screen around it.
enum ConfidenceTier: string
{
    case Deterministic = 'deterministic';

    case Confirmed = 'confirmed';

    case Candidate = 'candidate';

    public function labelKey(): string
    {
        return 'chains::drawer.confidence_tier.'.$this->value;
    }

    public function ariaKey(): string
    {
        return 'chains::drawer.confidence_aria.'.$this->value;
    }
}
