<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Modules\Core\Models\User;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

/**
 * Implementation of the Public SearchResultsProvider contract.
 *
 * Composes SearchQuery::palette() (top-5 transaction hits) with
 * EntityNameSearch::query() (name-only entity hits) into the
 * `{transactions, entities, totalCount}` shape the ⌘K palette consumes.
 *
 * The Plan 01 SearchServiceProvider's class_exists()-guarded block already
 * binds `SearchResultsProvider` → this FQCN. Do NOT edit the provider;
 * just creating this class with this exact FQCN is sufficient.
 *
 * The `totalCount` is the full transaction hit count (not capped at 5) so the
 * palette's "See all N results" row can display the true result set size.
 */
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

        // Top-5 transaction hits for the palette section
        $paletteRows = $this->searchQuery->palette($user, $query);

        // Full count for "See all N results" — run a lightweight count-only search
        $countPage = $this->searchQuery->search($user, $query, SearchFilters::empty(), null, null, 1);
        $totalCount = $countPage->totalCount;

        // Entity name hits (counterparties, categories, goals, pots, recurring)
        $entityHits = $this->entitySearch->query($user, $query);

        return [
            'transactions' => $paletteRows,
            'entities' => $entityHits,
            'totalCount' => $totalCount,
        ];
    }
}
