<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Modules\Core\Models\User;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Services\SearchQuery;

// Composes the palette's capped transaction hits with EntityNameSearch's name
// matches into the {transactions, entities, totalCount} shape it consumes. The
// count is the full hit total behind the cap, and comes off the same page the
// hits did rather than out of a second search of the whole history.
/**
 * @phpstan-import-type PaletteTransaction from SearchResultsProvider
 * @phpstan-import-type PaletteEntity from SearchResultsProvider
 */
final readonly class PaletteSectionComposer implements SearchResultsProvider
{
    public function __construct(
        private SearchQuery $searchQuery,
        private EntityNameSearch $entitySearch,
    ) {}

    /**
     * @return array{transactions: list<PaletteTransaction>, entities: list<PaletteEntity>, totalCount: int}
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

        ['hits' => $hits, 'totalCount' => $totalCount] = $this->searchQuery->palette($user, $query);

        return [
            'transactions' => $hits,
            'entities' => $this->entitySearch->query($user, $query),
            'totalCount' => $totalCount,
        ];
    }
}
