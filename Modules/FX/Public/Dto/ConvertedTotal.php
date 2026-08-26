<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;

// The result of adding buckets that were denominated in different currencies:
// a figure in one currency, plus the codes whose buckets never reached it. A
// caller that renders the figure without rendering the codes is showing a
// partial total as a whole one.
final readonly class ConvertedTotal
{
    /** @param list<string> $unconverted currency codes left out for want of a rate */
    public function __construct(
        public int $minor,
        public string $currency,
        public array $unconverted,
    ) {}

    public function money(): Money
    {
        return Money::ofMinor($this->minor, $this->currency);
    }

    public function isPartial(): bool
    {
        return $this->unconverted !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconverted);
    }
}
