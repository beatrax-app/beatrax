<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Ledger\Public\Dto\TransactionRowDto;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Http\Livewire\Concerns\HandlesClearedStatus;
use Modules\Ledger\Public\Services\TransactionListQuery;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchRowDto;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;
use stdClass;

final class TransactionsList extends Component
{
    use HandlesClearedStatus;
    use HandlesTaxTagging;

    // ~200-400 bytes JSON-encoded per row, so 500 rows stays well below
    // Livewire 4's 4MB snapshot limit. Oldest rows are trimmed past it.
    private const MAX_ACCUMULATED_ROWS = 500;

    public bool $fullHistory = false;

    public ?int $cursorId = null;

    public ?string $cursorPostedAt = null;

    // '' is the "no preference applied yet" sentinel; mount() resolves it
    // to the user's stored default before the first render.
    #[Url(as: 'currency', except: '')]
    public string $currency = '';

    #[Url(as: 'q', except: '')]
    public string $searchQuery = '';

    /** @var list<int> */
    #[Url(as: 'account', except: [])]
    public array $filterAccounts = [];

    /** @var list<int> */
    #[Url(as: 'category', except: [])]
    public array $filterCategories = [];

    /** @var list<int> */
    #[Url(as: 'counterparty', except: [])]
    public array $filterCounterparties = [];

    #[Url(as: 'after', except: '')]
    public string $filterAfter = '';

    #[Url(as: 'before', except: '')]
    public string $filterBefore = '';

    #[Url(as: 'amount_min', except: '')]
    public string $filterAmountMin = '';

    #[Url(as: 'amount_max', except: '')]
    public string $filterAmountMax = '';

    #[Url(as: 'amount_dir', except: 'both')]
    public string $filterAmountDir = 'both';

    public ?bool $preSearchFullHistory = null;

    /** @var list<array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged?: bool, taxCategoryShortName?: ?string, splitLegs?: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>, status?: string}> */
    public array $accumulatedRows = [];

    public bool $hasMore = false;

    public ?int $nextCursorId = null;

    public ?string $nextCursorPostedAt = null;

    // Public so Livewire dehydrates it into the encrypted snapshot — a
    // protected property re-initialises to [] on every request, making the
    // duplicate-append guard ineffective.
    /** @var array<int, true> */
    public array $appendedCursorIds = [];

    public function mount(CurrentUser $currentUser): void
    {
        if ($this->currency === '') {
            $pref = $currentUser->user()->default_currency_view;
            $this->currency = $pref === 'eur_only' ? 'eur' : 'original';
        }
    }

    public function isSearchActive(): bool
    {
        return $this->searchQuery !== ''
            || $this->filterAccounts !== []
            || $this->filterCategories !== []
            || $this->filterCounterparties !== []
            || $this->filterAfter !== ''
            || $this->filterBefore !== ''
            || $this->filterAmountMin !== ''
            || $this->filterAmountMax !== ''
            || $this->filterAmountDir !== 'both';
    }

    public function clearSearch(): void
    {
        $this->searchQuery = '';
        $this->filterAccounts = [];
        $this->filterCategories = [];
        $this->filterCounterparties = [];
        $this->filterAfter = '';
        $this->filterBefore = '';
        $this->filterAmountMin = '';
        $this->filterAmountMax = '';
        $this->filterAmountDir = 'both';

        if ($this->preSearchFullHistory !== null) {
            $this->fullHistory = $this->preSearchFullHistory;
            $this->preSearchFullHistory = null;
        }

        $this->cursorId = null;
        $this->cursorPostedAt = null;
        $this->accumulatedRows = [];
        $this->appendedCursorIds = [];
        $this->hasMore = false;
        $this->nextCursorId = null;
        $this->nextCursorPostedAt = null;
    }

    public function toggleFullHistory(): void
    {
        $this->fullHistory = ! $this->fullHistory;
        $this->cursorId = null;
        $this->cursorPostedAt = null;
        $this->accumulatedRows = [];
        $this->appendedCursorIds = [];
        $this->hasMore = false;
        $this->nextCursorId = null;
        $this->nextCursorPostedAt = null;
    }

    // The cursor comes from the server-side Livewire snapshot, never from a
    // browser parameter: the snapshot is HMAC-verified before hydration, so
    // a caller cannot forge a cursor to skip rows.
    public function loadMore(): void
    {
        $this->cursorId = $this->nextCursorId;
        $this->cursorPostedAt = $this->nextCursorPostedAt;
    }

    public function render(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
        DatabaseManager $db,
        TaxTagQuery $taxTagQuery,
        SearchQuery $searchQuery,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $this->normaliseFilterIds();

        return $this->isSearchActive()
            ? $this->renderSearch($currentUser, $views, $db, $taxTagQuery, $searchQuery, $codec, $session)
            : $this->renderList($currentUser, $listQuery, $views, $db, $taxTagQuery, $codec, $session);
    }

    // Livewire hands a #[Url] array to the view as well as to the query, so
    // cleaning it on the way to the query alone left the chip partial
    // subscripting [0] on a shape the address bar chose. Coerced on the
    // property instead, both readers see the same list.
    private function normaliseFilterIds(): void
    {
        $this->filterAccounts = self::positiveIds($this->filterAccounts);
        $this->filterCategories = self::positiveIds($this->filterCategories);
        $this->filterCounterparties = self::positiveIds($this->filterCounterparties);
    }

    // Captures $fullHistory on entry so clearSearch() can restore the view
    // the user was in, rather than whatever the search happened to span.
    private function renderSearch(
        CurrentUser $currentUser,
        ViewFactory $views,
        DatabaseManager $db,
        TaxTagQuery $taxTagQuery,
        SearchQuery $searchQuery,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $user = $currentUser->user();

        if ($this->preSearchFullHistory === null) {
            $this->preSearchFullHistory = $this->fullHistory;
        }

        $page = $searchQuery->search(
            $user,
            $this->searchQuery,
            $this->searchFilters(),
            $this->cursorId,
            $this->cursorPostedAt,
        );

        $this->accumulate(array_map(
            static fn (SearchRowDto $row): array => self::searchRowToArray($row),
            $page->rows,
        ));

        $this->hasMore = $page->hasMore;
        $this->nextCursorId = $page->nextCursorId;
        $this->nextCursorPostedAt = $page->nextCursorPostedAt;

        $rowIds = array_map(static fn (SearchRowDto $row): int => $row->id, $page->rows);
        $state = $this->decorateAccumulatedRows($rowIds, $db, $taxTagQuery, $currentUser, $codec, $session);

        // Rebuilt per render, never accumulated: a stale highlight must not
        // outlive the query that produced it.
        $searchRows = [];
        foreach ($page->rows as $row) {
            $searchRows[$row->id] = $row;
        }

        return $views->make('ledger::livewire.transactions-list', [
            ...$this->sharedViewData($state, $db, $user->id),
            'page' => $page,
            'chainTxIds' => [],
            'isSearchMode' => true,
            'searchTotalCount' => $page->totalCount,
            'searchTotalOut' => $page->totalOutMinor,
            'searchTotalIn' => $page->totalInMinor,
            'didYouMean' => $page->didYouMean,
            'searchRows' => $searchRows,
            'activeFilterCount' => $this->activeFilterCount(),
        ]);
    }

    private function renderList(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
        DatabaseManager $db,
        TaxTagQuery $taxTagQuery,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $user = $currentUser->user();
        $queryCurrency = $this->currency === 'eur' ? 'EUR' : null;

        $page = $this->fullHistory
            ? $listQuery->fullHistory($user, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency)
            : $listQuery->recent($user, daysBack: 90, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency);

        $this->accumulate(array_values(array_map(
            static fn (TransactionRowDto $row): array => self::rowToArray($row),
            $page->rows,
        )));

        $this->hasMore = $page->hasMore;
        $this->nextCursorId = $page->nextCursorId;
        $this->nextCursorPostedAt = $page->nextCursorPostedAt;

        $rowIds = array_values(array_map(static fn ($row): int => $row->id, $page->rows));
        $state = $this->decorateAccumulatedRows($rowIds, $db, $taxTagQuery, $currentUser, $codec, $session);

        return $views->make('ledger::livewire.transactions-list', [
            ...$this->sharedViewData($state, $db, $user->id),
            'page' => $page,
            'chainTxIds' => $this->chainTxIdsFor($rowIds, $db, $user->id),
            'isSearchMode' => false,
            'searchTotalCount' => 0,
            'searchTotalOut' => 0,
            'searchTotalIn' => 0,
            'didYouMean' => null,
            'searchRows' => [],
            'activeFilterCount' => 0,
        ]);
    }

    private function searchFilters(): SearchFilters
    {
        return new SearchFilters(
            accounts: $this->filterAccounts,
            categories: $this->filterCategories,
            counterparties: $this->filterCounterparties,
            after: $this->filterAfter !== '' ? $this->filterAfter : null,
            before: $this->filterBefore !== '' ? $this->filterBefore : null,
            amountMin: $this->filterAmountMin !== '' ? $this->filterAmountMin : null,
            amountMax: $this->filterAmountMax !== '' ? $this->filterAmountMax : null,
            amountDirection: $this->filterAmountDir,
        );
    }

    // array<array-key, mixed> deliberately: ?account[]= is reader-supplied, so
    // the declared list<int> describes what the rail sends, not what arrives.
    // A non-numeric member is dropped rather than cast, since (int) 'abc' is
    // the same 0 an unselected option sends, which would narrow to nothing.
    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function positiveIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $numeric = (int) $id;
            if ($numeric > 0) {
                $clean[] = $numeric;
            }
        }

        return $clean;
    }

    // appendedCursorIds stops a re-render at the same cursor appending the
    // same rows twice, which Livewire does whenever any property changes.
    /**
     * @param  list<array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged?: bool, taxCategoryShortName?: ?string, splitLegs?: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>, status?: string}>  $rows
     */
    private function accumulate(array $rows): void
    {
        $guardKey = $this->cursorId ?? 0;

        if ($guardKey === 0) {
            $this->accumulatedRows = $rows;
            $this->appendedCursorIds = [0 => true];
        } elseif (! isset($this->appendedCursorIds[$guardKey])) {
            foreach ($rows as $row) {
                $this->accumulatedRows[] = $row;
            }
            $this->appendedCursorIds[$guardKey] = true;
        }

        if (count($this->accumulatedRows) > self::MAX_ACCUMULATED_ROWS) {
            $this->accumulatedRows = array_slice($this->accumulatedRows, -self::MAX_ACCUMULATED_ROWS);
            $this->appendedCursorIds = [$guardKey => true];
        }
    }

    // Tax, split and cleared state are read for the whole accumulated set,
    // not just this page: a row loaded three pages ago is still on screen
    // and still has to show the truth after it is tagged or split.
    /**
     * @param  list<int>  $rowIds
     * @return array{taxState: array<int, array<string, mixed>>, splitLegs: array<int, mixed>, clearedState: array<int, string>}
     */
    private function decorateAccumulatedRows(
        array $rowIds,
        DatabaseManager $db,
        TaxTagQuery $taxTagQuery,
        CurrentUser $currentUser,
        SensitiveColumnCodec $codec,
        Session $session,
    ): array {
        $accIds = array_map(static fn (array $r): int => $r['id'], $this->accumulatedRows);
        $stateIds = array_values(array_unique([...$rowIds, ...$accIds]));

        $taxState = $this->taxTagStateFor($stateIds, $taxTagQuery, $currentUser);
        $splitLegs = $this->legsFor($stateIds, $db, $taxTagQuery, $currentUser->user()->id, $codec, $session);
        $clearedState = $this->clearedStatusFor($stateIds, $db, $currentUser);

        foreach ($this->accumulatedRows as &$accRow) {
            $accRowId = $accRow['id'];
            $accRow['taxTagged'] = $taxState[$accRowId]['taxTagged'] ?? false;
            $accRow['taxCategoryShortName'] = $taxState[$accRowId]['taxCategoryShortName'] ?? null;
            $accRow['splitLegs'] = $splitLegs[$accRowId] ?? [];
            $accRow['status'] = $clearedState[$accRowId] ?? ClearedStatus::Cleared->value;
        }
        unset($accRow);

        return ['taxState' => $taxState, 'splitLegs' => $splitLegs, 'clearedState' => $clearedState];
    }

    /**
     * @param  list<int>  $rowIds
     * @return array<int, bool>
     */
    private function chainTxIdsFor(array $rowIds, DatabaseManager $db, int $userId): array
    {
        if ($rowIds === []) {
            return [];
        }

        $matches = $db->connection()->table('chain_links')
            ->where('user_id', $userId)
            ->whereIn('state', ['confirmed', 'candidate'])
            ->where(function (Builder $q) use ($rowIds): void {
                $q->whereIn('from_transaction_id', $rowIds)
                    ->orWhereIn('to_transaction_id', $rowIds);
            })
            ->select(['from_transaction_id', 'to_transaction_id'])
            ->get();

        $chainTxIds = [];
        foreach ($matches as $m) {
            $fromId = is_numeric($m->from_transaction_id) ? (int) $m->from_transaction_id : 0;
            $toId = is_numeric($m->to_transaction_id ?? null) ? (int) $m->to_transaction_id : 0;
            if ($fromId !== 0) {
                $chainTxIds[$fromId] = true;
            }
            if ($toId !== 0) {
                $chainTxIds[$toId] = true;
            }
        }

        return $chainTxIds;
    }

    /**
     * @param  array{taxState: array<int, array<string, mixed>>, splitLegs: array<int, mixed>, clearedState: array<int, string>}  $state
     * @return array<string, mixed>
     */
    private function sharedViewData(array $state, DatabaseManager $db, int $userId): array
    {
        return [
            'accumulatedRows' => $this->accumulatedRows,
            'fullHistory' => $this->fullHistory,
            'currency' => $this->currency,
            'taxState' => $state['taxState'],
            'splitLegs' => $state['splitLegs'],
            'clearedState' => $state['clearedState'],
            'searchQuery' => $this->searchQuery,
            'availableAccounts' => $this->availableAccounts($db, $userId),
            'availableCategories' => $this->availableCategories($db, $userId),
        ];
    }

    public function activeFilterCount(): int
    {
        $count = 0;
        if ($this->filterAccounts !== []) {
            $count++;
        }
        if ($this->filterCategories !== []) {
            $count++;
        }
        if ($this->filterCounterparties !== []) {
            $count++;
        }
        if ($this->filterAfter !== '' || $this->filterBefore !== '') {
            $count++;
        }
        if ($this->filterAmountMin !== '' || $this->filterAmountMax !== '' || $this->filterAmountDir !== 'both') {
            $count++;
        }

        return $count;
    }

    /** @return list<array{id: int, name: string, currency: string}> */
    private function availableAccounts(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->all();

        return array_values(array_map(static function (object $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'currency' => is_string($row->default_currency) ? $row->default_currency : 'EUR',
            ];
        }, $rows));
    }

    /** @return list<array{id: int, name: string}> */
    private function availableCategories(DatabaseManager $db, int $userId): array
    {
        // Global (user_id IS NULL) OR user-owned: filtering on user_id alone
        // hid the chip on installs using only the seeded default tree.
        $rows = $db->connection()
            ->table('categories')
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->get(['id', ...CategoryDisplayName::bareColumns()])
            ->all();

        $options = array_values(array_map(static function (stdClass $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => CategoryDisplayName::fromRow($row) ?? '',
            ];
        }, $rows));

        // Sorted on what the reader sees; the stored English orders a
        // translated picker by a word that is not on screen.
        usort($options, static function (array $a, array $b): int {
            $byName = LocaleCollator::compare($a['name'], $b['name']);

            return $byName !== 0 ? $byName : $a['id'] <=> $b['id'];
        });

        return $options;
    }

    // Money is not Livewire-dehydratable, so a row carries the minor integer
    // and currency code and the blade rebuilds it via Money::ofMinor().
    /** @return array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>} */
    private static function rowToArray(TransactionRowDto $row): array
    {
        return [
            'id' => $row->id,
            'bookedAt' => $row->bookedAt,
            'counterpartyName' => $row->counterpartyName,
            'counterpartySlug' => $row->counterpartySlug,
            'categoryId' => $row->categoryId,
            'amountMinor' => $row->amount->toMinor(),
            'amountCurrency' => $row->amount->currency(),
            'secondaryMinor' => $row->secondaryAmount?->toMinor(),
            'secondaryCurrency' => $row->secondaryAmount?->currency(),
            'taxTagged' => false,
            'taxCategoryShortName' => null,
            'splitLegs' => [],
        ];
    }

    /** @return array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>} */
    private static function searchRowToArray(SearchRowDto $row): array
    {
        return [
            'id' => $row->id,
            'bookedAt' => $row->bookedAt,
            'counterpartyName' => $row->counterpartyName,
            'counterpartySlug' => $row->counterpartySlug,
            'categoryId' => $row->categoryId,
            'amountMinor' => $row->amountMinor,
            'amountCurrency' => $row->amountCurrency,
            'secondaryMinor' => $row->secondaryMinor,
            'secondaryCurrency' => $row->secondaryCurrency,
            'taxTagged' => false,
            'taxCategoryShortName' => null,
            'splitLegs' => [],
        ];
    }

    // No user_id filter: every id arrives already user-scoped by the list
    // query that produced it, so the transaction_id join cannot leak legs.
    /**
     * @param  array<int>  $transactionIds
     * @return array<int, list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>>
     */
    private function legsFor(array $transactionIds, DatabaseManager $db, TaxTagQuery $taxTagQuery, int $userId, SensitiveColumnCodec $codec, Session $session): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = $db->connection()
            ->table('transaction_splits')
            ->leftJoin('categories', 'transaction_splits.category_id', '=', 'categories.id')
            ->whereIn('transaction_splits.transaction_id', $transactionIds)
            ->orderBy('transaction_splits.transaction_id')
            ->orderBy('transaction_splits.sort_order')
            ->get([
                'transaction_splits.id',
                'transaction_splits.transaction_id',
                'transaction_splits.settled_amount_minor',
                'transaction_splits.settled_currency',
                'transaction_splits.note',
                ...CategoryDisplayName::columns('categories'),
            ]);

        // Leg-scoped tax state — one batched query, keyed by
        // "{txId}:{legId}". Not merged into $taxState (whole-transaction only).
        $legTaxState = $taxTagQuery->forTransactionIdsWithLegs($userId, $transactionIds);

        $map = [];
        foreach ($rows as $row) {
            $txId = is_numeric($row->transaction_id) ? (int) $row->transaction_id : 0;
            $legId = is_numeric($row->id) ? (int) $row->id : 0;
            $legTag = $legTaxState[$txId.':'.$legId] ?? null;

            $legNote = is_string($row->note)
                ? $codec->decryptValue('transaction_splits', 'note', $row->note, $userId, $session)['value']
                : null;

            $map[$txId] ??= [];
            $map[$txId][] = [
                'id' => $legId,
                'categoryName' => CategoryDisplayName::fromRow($row, 'category') ?? '—',
                'amountMinor' => is_numeric($row->settled_amount_minor) ? (int) $row->settled_amount_minor : 0,
                'amountCurrency' => is_string($row->settled_currency) ? $row->settled_currency : 'EUR',
                'note' => $legNote,
                'taxTagged' => $legTag !== null,
                'taxCategoryShortName' => $legTag->deductionCategoryShortName ?? null,
            ];
        }

        return $map;
    }
}
