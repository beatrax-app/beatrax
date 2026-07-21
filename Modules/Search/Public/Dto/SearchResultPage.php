<?php

declare(strict_types=1);

namespace Modules\Search\Public\Dto;

// Mirrors TransactionListPage, extended with settled-EUR aggregate
// totals across ALL results (not just the current page) and an
// optional "did you mean" string surfaced only when FTS5 returned 0
// results.
final readonly class SearchResultPage
{
    /**
     * @param  list<SearchRowDto>  $rows
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
    ) {}
}
