<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Exceptions;

use RuntimeException;

// The inverse of ChainLinkRequiresConcretePartnerException: dismissal is
// for hint-shaped rows only, so a row that already has a concrete
// to_transaction_id must route through confirm/reject instead. Both
// halves of that pair now carry a type a caller can catch.
final class ChainLinkNotDismissableException extends RuntimeException
{
    public function __construct(public readonly int $chainLinkId)
    {
        parent::__construct(sprintf(
            'DismissChainLinkHint refuses chain_link %d — it has a concrete to_transaction_id, '
                .'use confirm/reject instead.',
            $chainLinkId,
        ));
    }
}
