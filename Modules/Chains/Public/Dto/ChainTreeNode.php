<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// The root node is encoded as $chainLinkId null + $kind "root"; every
// other node carries its chain_links.id.
final class ChainTreeNode extends Data
{
    /**
     * @param  array<ChainTreeNode>  $children
     */
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $chainLinkId,
        public readonly string $counterpartyName,
        public readonly Money $amount,
        public readonly CarbonImmutable $bookedAt,
        public readonly string $accountName,
        public readonly string $kind,
        public readonly string $confidenceTier,
        public readonly array $children = [],
        public readonly ?string $counterpartySlug = null,
    ) {}
}
