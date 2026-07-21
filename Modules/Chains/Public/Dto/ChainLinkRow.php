<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// One instance per candidate chain_links row for /chains/review.
// confirmsRemaining counts down to the auto-promotion threshold; the
// *CounterpartySlug fields are null when the transaction has no resolved
// counterparty, falling back to plain text instead of a profile link.
final class ChainLinkRow extends Data
{
    public function __construct(
        public readonly int $chainLinkId,
        public readonly string $kind,
        public readonly string $state,
        public readonly float $confidence,
        public readonly int $fromTransactionId,
        public readonly string $fromCounterparty,
        public readonly Money $fromAmount,
        public readonly int $toTransactionId,
        public readonly string $toCounterparty,
        public readonly Money $toAmount,
        public readonly CarbonImmutable $fromPostedAt,
        public readonly CarbonImmutable $toPostedAt,
        public readonly int $confirmsRemaining,
        public readonly ?string $fromCounterpartySlug = null,
        public readonly ?string $toCounterpartySlug = null,
    ) {}
}
