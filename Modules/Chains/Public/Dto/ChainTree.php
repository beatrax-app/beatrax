<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Spatie\LaravelData\Data;

// $rootTransactionId is the transaction the user clicked into; $nodes is
// the ordered waterfall (root -> funder) with each node carrying its own
// children for ICS bulk-settle fan-out blocks.
final class ChainTree extends Data
{
    /**
     * @param  array<ChainTreeNode>  $nodes
     */
    public function __construct(
        public readonly int $rootTransactionId,
        public readonly array $nodes,
    ) {}
}
