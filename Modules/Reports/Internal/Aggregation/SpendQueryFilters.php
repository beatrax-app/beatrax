<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Modules\Ledger\Public\Enums\AmountDirection;

final readonly class SpendQueryFilters
{
    /**
     * @param  list<int>  $accountIds  restrict to these account ids (empty = no restriction); applied alongside the user_id guard, so a foreign id only narrows this user's own result, never widens it
     * @param  list<int>  $categoryIds  restrict to these category ids (empty = no restriction)
     * @param  list<int>  $counterpartyIds  restrict to these counterparty ids (empty = no restriction)
     * @param  ?int  $amountMinMinor  restrict to rows whose ABS(settled_amount_minor) >= this (null = no restriction)
     * @param  ?int  $amountMaxMinor  restrict to rows whose ABS(settled_amount_minor) <= this (null = no restriction)
     */
    public function __construct(
        public array $accountIds = [],
        public array $categoryIds = [],
        public array $counterpartyIds = [],
        public ?int $amountMinMinor = null,
        public ?int $amountMaxMinor = null,
        public string $amountDirection = AmountDirection::Both->value,
    ) {}

    public function hasAmountBounds(): bool
    {
        return $this->amountMinMinor !== null || $this->amountMaxMinor !== null;
    }

    // The bounds are the only part of this set that is denominated in a
    // currency, so they are the only part a per-currency re-scoping replaces.
    public function withAmountBounds(?int $minMinor, ?int $maxMinor): self
    {
        return new self(
            accountIds: $this->accountIds,
            categoryIds: $this->categoryIds,
            counterpartyIds: $this->counterpartyIds,
            amountMinMinor: $minMinor,
            amountMaxMinor: $maxMinor,
            amountDirection: $this->amountDirection,
        );
    }

    public function withoutAmountBounds(): self
    {
        return $this->withAmountBounds(null, null);
    }
}
