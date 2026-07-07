<?php

declare(strict_types=1);

namespace Modules\Search\Public\Dto;

/**
 * Typed filter parameter object consumed by SearchQuery::search().
 *
 * Mirrors the #[Url] filter property set on TransactionsList so the same
 * filter state (from URL params) can be passed through the public API
 * without leaking Livewire internals.
 *
 * All properties are optional/nullable and default to their "no filter"
 * value so callers need only set the active dimensions.
 */
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
     * @param  string  $amountDirection  'in' | 'out' | 'both'.
     */
    public function __construct(
        public array $accounts = [],
        public array $categories = [],
        public array $counterparties = [],
        public ?string $after = null,
        public ?string $before = null,
        public ?string $amountMin = null,
        public ?string $amountMax = null,
        public string $amountDirection = 'both',
    ) {}

    /**
     * Returns a SearchFilters instance with no active filters.
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * Returns true when at least one filter dimension is non-default.
     */
    public function isActive(): bool
    {
        return $this->accounts !== []
            || $this->categories !== []
            || $this->counterparties !== []
            || $this->after !== null
            || $this->before !== null
            || $this->amountMin !== null
            || $this->amountMax !== null
            || $this->amountDirection !== 'both';
    }
}
