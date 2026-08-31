<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// What a settlement's legs add up to over ALL of them, not over the ones a page
// chose to render. /chains caps how many legs a card lists, and a count or a
// total taken from the listed ones alone contradicts the settlement heading
// above them.
final class SettlementTotals extends Data
{
    /**
     * @param  list<Money>  $totals  one figure per currency present in the legs
     */
    public function __construct(
        public readonly int $settlementTransactionId,
        public readonly int $legCount,
        public readonly array $totals,
        public readonly bool $hasCandidateLeg,
    ) {}
}
