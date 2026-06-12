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

/**
 * Server-backed palette search endpoint.
 *
 * Registered by SearchServiceProvider's Plan 01 class_exists()-guarded block
 * as the 'search.palette-search-endpoint' Livewire component. Mounted in both
 * layouts (app.blade.php + dev-shell.blade.php) so every authenticated page
 * has this component available.
 *
 * Security (T-08-11): all results are scoped to the authenticated user via
 * CurrentUser → SearchQuery's user_id predicates + EntityNameSearch's user_id
 * predicates. A cross-user query is structurally impossible.
 *
 * Method-DI pattern: search() injects CurrentUser, SearchResultsProvider,
 * SearchQuery, and EntityNameSearch from the container (per project pattern
 * established in CommandPaletteModal). No constructor injection.
 *
 * Gate (D-01, Pitfall-5): queries shorter than 2 characters return empty
 * arrays immediately — no FTS query fires.
 */
final class PaletteSearchEndpoint extends Component
{
    /** Raw query string from the palette JS client. */
    public string $query = '';

    /**
     * Top-5 transaction hits for the Transactions section (D-01, D-19).
     *
     * Each element is a two-line row shape:
     *   id, counterpartyName, amount (formatted string), snippet, url
     *
     * @var list<array{id: int, counterpartyName: ?string, amount: string, snippet: ?string, url: string}>
     */
    public array $transactionHits = [];

    /**
     * Entity name hits (D-28): counterparties, categories, goals, pots, recurring.
     *
     * Each element: {id, type, label, url}
     *
     * @var list<array{id: int, type: string, label: string, url: string}>
     */
    public array $entityHits = [];

    /** Full transaction hit count — used by "See all N results" row (D-01). */
    public int $totalCount = 0;

    /**
     * Server fetch action called by palette.js on debounce (>= 2 chars).
     *
     * All dependencies are method-injected from the container following
     * the CommandPaletteModal render() pattern. The SearchResultsProvider
     * param uses the concrete SearchQuery + EntityNameSearch path; the
     * SearchResultsProvider contract param on CommandPaletteModal is the
     * boundary-safe injection point (DevMode → Search Public only).
     *
     * The method also accepts SearchQuery and EntityNameSearch directly
     * so the palette section can use both the composed provider path AND
     * the direct path — only SearchResultsProvider is used here to keep
     * the boundary clean (T-08-12: DevMode may only import Search Public).
     */
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
