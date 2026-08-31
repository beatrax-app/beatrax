<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// The ranking and what the ranking left out, together, because the card that
// draws one has to say the other: a card answering "no categorized expenses
// yet" over rows the reader can see has hidden the narrowing rather than
// reported it.
final class TopCategories extends Data
{
    /**
     * @param  list<TopCategoryRow>  $rows  largest share first
     * @param  Money  $refunded  what came back out of the categories left unranked, as a magnitude
     */
    public function __construct(
        public readonly array $rows,
        public readonly Money $refunded,
        public readonly int $refundedCategoryCount,
    ) {}

    public static function none(string $currency): self
    {
        return new self([], Money::ofMinor(0, $currency), 0);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function hasRefundedCategories(): bool
    {
        return $this->refundedCategoryCount > 0;
    }
}
