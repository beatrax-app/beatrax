<?php

declare(strict_types=1);

namespace Modules\Search\Public\Dto;

/**
 * One cursor-paginated page of search results.
 *
 * Mirrors TransactionListPage for structural consistency and extends it with:
 *   - settled-EUR aggregate totals for the summary strip (D-17)
 *   - a "did you mean" suggestion string for the no-results state (D-21)
 *
 * $totalOutMinor and $totalInMinor are the settled-EUR aggregate totals across
 * ALL results for the query (not just the current page), expressed in minor
 * units (cents). Negative = outgoing, positive = incoming.
 *
 * $didYouMean is non-null only when FTS5 returned 0 results and the
 * DidYouMeanSuggester found a close enough alternative (levenshtein ≤ 2,
 * query length ≥ 4). The string is a plain-text suggestion, not HTML.
 */
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
        /** Fuzzy spelling suggestion when $totalCount is 0. */
        public ?string $didYouMean,
    ) {}
}
