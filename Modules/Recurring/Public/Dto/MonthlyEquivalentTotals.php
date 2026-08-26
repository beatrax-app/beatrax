<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;

// recurring_series.monthly_equivalent_minor is derived from latest_amount_minor,
// so it is denominated in the series' own latest_currency. These three are what
// comes out once every series has been through a rate.
final readonly class MonthlyEquivalentTotals
{
    /** @param list<string> $unconverted currency codes left out for want of a rate */
    public function __construct(
        public Money $expense,
        public Money $income,
        public Money $net,
        public array $unconverted,
    ) {}

    public function isPartial(): bool
    {
        return $this->unconverted !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconverted);
    }
}
