<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Presentation;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;

// One row under a settlement card, already turned the right way round: which
// endpoint of the chain_link is the leg and which is the settlement depends on
// the kind, and the view may not be the place that decides.
final readonly class SettlementLeg
{
    public function __construct(
        public int $chainLinkId,
        public int $transactionId,
        public string $counterparty,
        public ?string $counterpartySlug,
        public Money $amount,
        public CarbonImmutable $postedAt,
        public string $state,
    ) {}
}
