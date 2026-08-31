<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Dto;

// What one grid day's balance line actually knows. A currency the rate table
// cannot reach is left out of $minor and named in $unconvertedCurrencies, and
// $isNegative is the risk answer $minor alone cannot give: a balance overdrawn
// only in that currency converts to nothing at all.
final readonly class DayBalanceDto
{
    /**
     * @param  list<string>  $unconvertedCurrencies
     * @param  bool  $hasFigure  false when every bucket the day holds was unpriced,
     *                           so $minor is not a small balance but no balance
     */
    public function __construct(
        public int $minor,
        public bool $isComputing,
        public array $unconvertedCurrencies = [],
        public bool $isNegative = false,
        public bool $hasFigure = true,
    ) {}

    public function isKnown(): bool
    {
        return ! $this->isComputing && $this->hasFigure;
    }
}
