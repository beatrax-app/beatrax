<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;

// The result of adding buckets denominated in different currencies: a figure
// in one currency, the rates that brought the others into it, and the codes no
// rate reached. A caller that renders the figure and neither of those shows a
// partial total as a whole one, and a converted one as needing no conversion.
final readonly class ConvertedTotal
{
    /** @param list<string> $unconverted currency codes left out for want of a rate */
    public function __construct(
        public int $minor,
        public string $currency,
        public array $unconverted,
        public RateSet $rates,
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

    public function disclosure(): ConversionDisclosure
    {
        return ConversionDisclosure::of($this->rates, $this->unconverted);
    }
}
