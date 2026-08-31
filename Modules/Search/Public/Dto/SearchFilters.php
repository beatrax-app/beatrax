<?php

declare(strict_types=1);

namespace Modules\Search\Public\Dto;

use Modules\Ledger\Public\Enums\AmountDirection;

// Mirrors TransactionsList's #[Url] filter property set so the same
// filter state (from URL params) can pass through the public API
// without leaking Livewire internals. Every property defaults to its
// "no filter" value.
final readonly class SearchFilters
{
    /**
     * @param  list<int>  $accounts  Account IDs to restrict results to (empty = all).
     * @param  list<int>  $categories  Category IDs to restrict results to (empty = all).
     * @param  list<int>  $counterparties  Counterparty IDs to restrict results to (empty = all).
     * @param  ?string  $after  ISO date string (Y-m-d) — include transactions on or after this date.
     * @param  ?string  $before  ISO date string (Y-m-d) — include transactions on or before this date.
     * @param  ?string  $amountMin  Minimum absolute amount as decimal string (e.g. "10.00").
     * @param  ?string  $amountMax  Maximum absolute amount as decimal string (e.g. "500.00").
     * @param  list<string>  $types  transactions.type values to restrict to (empty = all).
     * @param  bool  $uncategorized  Restrict to transactions carrying no category at all — a positive filter, not the absence of $categories, since "no category" is a bucket a report can group by and open.
     */
    public function __construct(
        public array $accounts = [],
        public array $categories = [],
        public array $counterparties = [],
        public ?string $after = null,
        public ?string $before = null,
        public ?string $amountMin = null,
        public ?string $amountMax = null,
        public string $amountDirection = AmountDirection::Both->value,
        public array $types = [],
        public bool $uncategorized = false,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function isActive(): bool
    {
        return $this->accounts !== []
            || $this->categories !== []
            || $this->counterparties !== []
            || $this->after !== null
            || $this->before !== null
            || $this->amountMin !== null
            || $this->amountMax !== null
            || $this->amountDirection !== AmountDirection::Both->value
            || $this->types !== []
            || $this->uncategorized;
    }
}
