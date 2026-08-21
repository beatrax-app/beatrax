<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// confirmsRemaining counts down to the auto-promotion threshold.
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
