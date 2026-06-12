<?php

declare(strict_types=1);

namespace Modules\Search\Public\Dto;

/**
 * Display-shape for one result row returned by SearchQuery.
 *
 * Mirrors TransactionRowDto fields so the existing transaction-row Blade
 * partial can render search results without a separate template:
 *   - int $id, string $bookedAt, ?string $counterpartyName, ?int $categoryId,
 *     ?string $categoryName, int $amountMinor, string $amountCurrency, etc.
 *
 * Search-specific additions:
 *   - $highlightedCounterparty: server-built HTML with <mark> tags around the
 *     matched substring (from FTS5 highlight()), or null when the counterparty
 *     did not contain the match.
 *   - $snippet: one-line excerpt from the description or tax note with <mark>
 *     tags (from FTS5 snippet()), or null when no in-body match was found.
 */
final readonly class SearchRowDto
{
    public function __construct(
        public int $id,
        public string $bookedAt,
        public ?string $counterpartyName,
        public ?string $counterpartySlug,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $amountMinor,
        public string $amountCurrency,
        public ?int $secondaryMinor,
        public ?string $secondaryCurrency,
        /** Pre-built HTML with <mark> spans highlighting the matched substring in the counterparty name. Null when the match did not touch the counterparty. */
        public ?string $highlightedCounterparty,
        /** One-line excerpt with <mark> spans from description or tax note. Null when no body match. */
        public ?string $snippet,
    ) {}
}
