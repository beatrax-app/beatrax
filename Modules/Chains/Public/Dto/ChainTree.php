<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Spatie\LaravelData\Data;

final class ChainTree extends Data
{
    /**
     * @param  array<ChainTreeNode>  $nodes  the waterfall, ordered root to funder
     */
    public function __construct(
        public readonly int $rootTransactionId,
        public readonly array $nodes,
    ) {}
}
