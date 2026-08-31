<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Enums;

// The `chain_links.resolver` vocabulary, in an 8-character column: `auto` is
// every row a resolver proposes, `rule` is the auto-promotion learning loop,
// `user` is a hand-made link. A twelve-character literal was written here once
// and only SQLite's indifference to VARCHAR length kept it.
enum ChainLinkResolver: string
{
    case Auto = 'auto';

    case Rule = 'rule';

    case User = 'user';
}
