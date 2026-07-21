<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// The root node has $chainLinkId null and $kind = 'root'; funder legs
// carry their chain_links.id and a $confidenceTier mapping (state,
// resolver, confidence) to the UI's Deterministic/Confirmed/Candidate chip.
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
