<?php

declare(strict_types=1);

namespace Modules\Search\Public\Dto;

// Mirrors TransactionListPage, extended with aggregate totals across ALL
// results (not just the current page), in the reader's own base currency, and
// an optional "did you mean" string surfaced only when FTS5 returned 0
// results.
final readonly class SearchResultPage
{
    /**
     * @param  list<SearchRowDto>  $rows
     * @param  list<string>  $unconvertedCurrencies  codes left out of the two totals for want of a rate
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public int $totalOutMinor,
        public int $totalInMinor,
        public bool $hasMore,
        public ?int $nextCursorId,
        public ?string $nextCursorPostedAt,
        public ?string $didYouMean,
        public array $unconvertedCurrencies = [],
    ) {}

    public function isPartial(): bool
    {
        return $this->unconvertedCurrencies !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconvertedCurrencies);
    }
}
