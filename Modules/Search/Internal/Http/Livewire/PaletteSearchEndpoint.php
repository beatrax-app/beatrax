<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Services\SearchQuery;

// Server-backed ⌘K palette search action, mounted in both the main and
// dev-shell layouts. Every result is scoped to the authenticated user
// via CurrentUser feeding SearchQuery/EntityNameSearch's user_id
// predicates, so a cross-user query is structurally impossible.
/**
 * @link ../../../../../.docs/features/search/architecture.md
 */
final class PaletteSearchEndpoint extends Component
{
    public string $query = '';

    /**
     * @var list<array{id: int, counterpartyName: ?string, amount: string, snippet: ?string, url: string}>
     */
    public array $transactionHits = [];

    /**
     * @var list<array{id: int, type: string, label: string, url: string}>
     */
    public array $entityHits = [];

    public int $totalCount = 0;

    public function search(
        string $q,
        CurrentUser $currentUser,
        SearchResultsProvider $provider,
    ): void {
        $this->query = $q;

        if (strlen($q) < 2) {
            $this->transactionHits = [];
            $this->entityHits = [];
            $this->totalCount = 0;

            return;
        }

        $user = $currentUser->user();
        $sections = $provider->paletteSections($user, $q);

        /** @var list<array{id: int, counterpartyName: ?string, amount: string, snippet: ?string, url: string}> $txHits */
        $txHits = [];
        foreach ($sections['transactions'] as $row) {
            $txHits[] = [
                'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0,
                'counterpartyName' => isset($row['counterpartyName']) && is_string($row['counterpartyName']) ? $row['counterpartyName'] : null,
                'amount' => isset($row['amount']) && is_string($row['amount']) ? $row['amount'] : '',
                'snippet' => isset($row['snippet']) && is_string($row['snippet']) ? $row['snippet'] : null,
                'url' => isset($row['url']) && is_string($row['url']) ? $row['url'] : '',
            ];
        }

        /** @var list<array{id: int, type: string, label: string, url: string}> $entityHits */
        $entityHits = [];
        foreach ($sections['entities'] as $row) {
            $entityHits[] = [
                'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0,
                'type' => isset($row['type']) && is_string($row['type']) ? $row['type'] : '',
                'label' => isset($row['label']) && is_string($row['label']) ? $row['label'] : '',
                'url' => isset($row['url']) && is_string($row['url']) ? $row['url'] : '',
            ];
        }

        $this->transactionHits = $txHits;
        $this->entityHits = $entityHits;
        $this->totalCount = $sections['totalCount'];
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('search::livewire.palette-search-endpoint');
    }
}
