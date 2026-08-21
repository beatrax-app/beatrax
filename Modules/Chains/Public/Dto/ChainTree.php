<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Spatie\LaravelData\Data;

// $nodes is the waterfall ordered root -> funder.
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
