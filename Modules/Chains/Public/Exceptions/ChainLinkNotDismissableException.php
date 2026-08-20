<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Exceptions;

use RuntimeException;

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
