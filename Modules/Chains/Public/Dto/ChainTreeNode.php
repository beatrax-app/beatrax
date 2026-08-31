<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Chains\Public\Enums\ConfidenceTier;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// The root node is encoded as $chainLinkId null + $kind "root"; every
// other node carries its chain_links.id. The day is posted_at because that is
// the day every list, the detail page and the chains index print; the
// settlement resolvers still match legs on booked_at, which no node carries.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-list-sorted-by-a-column-it-does-not-show
 */
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
        public readonly CarbonImmutable $postedAt,
        public readonly string $accountName,
        public readonly string $kind,
        public readonly ConfidenceTier $confidenceTier,
        public readonly array $children = [],
        public readonly ?string $counterpartySlug = null,
    ) {}
}
