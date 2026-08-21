<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class ChainLinkHintRow extends Data
{
    /**
     * @param  list<string>  $evidenceLines  Human-readable bullet
     *                                       points derived from the
     *                                       hint's `evidence` JSON.
     *                                       Empty when evidence is
     *                                       absent or unparseable —
     *                                       the row still renders.
     */
    public function __construct(
        public readonly int $chainLinkId,
        public readonly string $kind,
        public readonly float $confidence,
        public readonly int $fromTransactionId,
        public readonly string $fromCounterparty,
        public readonly Money $fromAmount,
        public readonly CarbonImmutable $fromPostedAt,
        public readonly string $fromAccountName,
        public readonly array $evidenceLines,
        public readonly ?string $fromCounterpartySlug = null,
    ) {}
}
