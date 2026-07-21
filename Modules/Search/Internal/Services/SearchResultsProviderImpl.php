<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Modules\Core\Models\User;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// Composes SearchQuery::palette() (top-5 transaction hits) with
// EntityNameSearch::query() into the {transactions, entities,
// totalCount} shape the palette consumes. totalCount is the full
// transaction hit count (not capped at 5) for the "See all N" row.
final class SearchResultsProviderImpl implements SearchResultsProvider
{
    public function __construct(
        private readonly SearchQuery $searchQuery,
        private readonly EntityNameSearch $entitySearch,
    ) {}

    /**
     * @return array{transactions: list<array<string,mixed>>, entities: list<array<string,mixed>>, totalCount: int}
     */
    public function paletteSections(User $user, string $query): array
    {
        if ($query === '') {
            return [
                'transactions' => [],
                'entities' => [],
                'totalCount' => 0,
            ];
        }

        $paletteRows = $this->searchQuery->palette($user, $query);

        $countPage = $this->searchQuery->search($user, $query, SearchFilters::empty(), null, null, 1);
        $totalCount = $countPage->totalCount;

        $entityHits = $this->entitySearch->query($user, $query);

        return [
            'transactions' => $paletteRows,
            'entities' => $entityHits,
            'totalCount' => $totalCount,
        ];
    }
}
