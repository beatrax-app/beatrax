<?php

declare(strict_types=1);

namespace Modules\Search\Public\Dto;

// Mirrors TransactionRowDto so the existing transaction-row partial
// can render search results without a separate template; adds
// pre-built <mark>-highlighted HTML for the counterparty match and
// a body snippet (both null when no match touched that field).
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
        public ?string $highlightedCounterparty,
        public ?string $snippet,
    ) {}
}
