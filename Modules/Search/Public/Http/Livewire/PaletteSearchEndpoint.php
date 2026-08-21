<?php

declare(strict_types=1);

namespace Modules\Search\Public\Http\Livewire;

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

    private const MIN_QUERY_LENGTH = 2;

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

        $this->transactionHits = $this->normalizeTransactionHits($sections['transactions']);
        $this->entityHits = $this->normalizeEntityHits($sections['entities']);
        $this->totalCount = $sections['totalCount'];
    }

    private function resetResults(): void
    {
        $this->transactionHits = [];
        $this->entityHits = [];
        $this->totalCount = 0;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, counterpartyName: ?string, amount: string, snippet: ?string, url: string}>
     */
    private function normalizeTransactionHits(array $rows): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $hits[] = [
                'id' => $this->intField($row, 'id'),
                'counterpartyName' => $this->nullableString($row, 'counterpartyName'),
                'amount' => $this->stringOrEmpty($row, 'amount'),
                'snippet' => $this->nullableString($row, 'snippet'),
                'url' => $this->stringOrEmpty($row, 'url'),
            ];
        }

        return $hits;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function normalizeEntityHits(array $rows): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $hits[] = [
                'id' => $this->intField($row, 'id'),
                'type' => $this->stringOrEmpty($row, 'type'),
                'label' => $this->stringOrEmpty($row, 'label'),
                'url' => $this->stringOrEmpty($row, 'url'),
            ];
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringOrEmpty(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('search::livewire.palette-search-endpoint');
    }
}
