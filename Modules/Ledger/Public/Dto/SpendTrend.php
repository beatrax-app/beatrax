<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// All amounts are EUR minor units; totalDeltaMinor and each
// CategoryDelta::deltaMinor are positive when spend went up.
final class SpendTrend extends Data
{
    /**
     * @param  list<CategoryDelta>  $movers
     */
    public function __construct(
        public readonly int $currentTotalMinor,
        public readonly int $previousTotalMinor,
        public readonly int $totalDeltaMinor,
        public readonly string $currency,
        public readonly string $currentLabel,
        public readonly string $previousLabel,
        public readonly array $movers,
    ) {}

    public function hasComparison(): bool
    {
        return $this->currentTotalMinor > 0 || $this->previousTotalMinor > 0;
    }
}
