<?php

declare(strict_types=1);

namespace Modules\Search\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Search\Public\Contracts\SearchResultsProvider;

// Server-backed ⌘K palette search action, mounted in both the main and
// dev-shell layouts. Every result is scoped to the authenticated user
// through CurrentUser, whose id reaches every user_id predicate behind the
// provider, so a cross-user query is structurally impossible.
/**
 * @phpstan-import-type PaletteTransaction from SearchResultsProvider
 * @phpstan-import-type PaletteEntity from SearchResultsProvider
 */
final class PaletteSearchEndpoint extends Component
{
    public string $query = '';

    /**
     * @var list<PaletteTransaction>
     */
    public array $transactionHits = [];

    /**
     * @var list<PaletteEntity>
     */
    public array $entityHits = [];

    public int $totalCount = 0;

    private const int MIN_QUERY_LENGTH = 2;

    public function search(
        string $q,
        CurrentUser $currentUser,
        SearchResultsProvider $provider,
    ): void {
        $this->query = $q;

        if (strlen($q) < self::MIN_QUERY_LENGTH) {
            $this->resetResults();

            return;
        }

        $sections = $provider->paletteSections($currentUser->user(), $q);

        $this->transactionHits = $sections['transactions'];
        $this->entityHits = $sections['entities'];
        $this->totalCount = $sections['totalCount'];
    }

    private function resetResults(): void
    {
        $this->transactionHits = [];
        $this->entityHits = [];
        $this->totalCount = 0;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('search::livewire.palette-search-endpoint');
    }
}
