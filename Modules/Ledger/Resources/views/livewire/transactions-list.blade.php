@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    // EUR amounts render in Dutch locale (e.g. `€ 68,86`); non-EUR amounts
    // render in US English locale (e.g. `$74.43`) so the symbol prefix
    // matches the user's mental model of the foreign currency. brick/money
    // routes the locale through ext-intl's NumberFormatter under the hood.
    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    // Whether the given Money value is positive (for phone card coloring).
    // Uses toMinor() > 0 so zero-value rows are not highlighted emerald.
    $isPositive = static fn (Money $money): bool => $money->toMinor() > 0;

    // Helper to reconstruct a Money VO from the scalar array stored in
    // $accumulatedRows (TransactionsList serialises Money as minor+currency
    // pairs so Livewire can dehydrate the state). Used by the phone card list.
    // @param array{amountMinor: int, amountCurrency: string} $row
    $rowMoney = static fn (array $row): Money => Money::ofMinor($row['amountMinor'], $row['amountCurrency']);

    // Tax state map: array<int, array{taxTagged: bool, taxCategoryShortName: ?string}>
    // Batch-loaded once per render — no N+1 (Pitfall 1).
    $taxState ??= [];

    // Cleared status map: array<int, string> (SC-1, D-11). Batch-loaded once
    // per render via HandlesClearedStatus::clearedStatusFor() — no N+1.
    $clearedState ??= [];

    // Search mode flags (passed by TransactionsList::render() in both branches)
    $isSearchMode ??= false;
    $searchQuery ??= '';
    $searchTotalCount ??= 0;
    $searchTotalOut ??= 0;
    $searchTotalIn ??= 0;
    $didYouMean ??= null;
    $searchRows ??= [];
    $activeFilterCount ??= 0;
    $availableAccounts ??= [];
    $availableCategories ??= [];

    // Split legs (Phase 13.1 Plan 06): array<int, list<array{...}>> keyed by
    // transaction id, batch-loaded once per render (no N+1 — Pitfall 1).
    // A row is a split parent when it has >= 2 legs (leg-row presence, NEVER
    // category_id nullity — Pitfall 1 / D-11).
    $splitLegs ??= [];

    // Format minor-unit amount (settled EUR, nl_NL) for the summary strip
    $fmtMinor = static fn (int $minor): string => Money::ofMinor(abs($minor), 'EUR')->format('nl_NL');
@endphp

<div class="space-y-6">
    {{-- Tax tag picker — rendered once for the whole list (not per-row). --}}
    @include('tax::components.tax-tag-popover')

    {{-- ============================================================
         SEARCH TOOLBAR (Phase 8 — always visible on /transactions)
         ============================================================ --}}
    @include('ledger::livewire.partials.search-toolbar')

    <header class="flex items-end justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('ledger::list.heading') }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                @if ($isSearchMode)
                    {{ Lang::get('ledger::list.subtitle_searching') }}
                @else
                    {{ $fullHistory ? Lang::get('ledger::list.subtitle_full') : Lang::get('ledger::list.subtitle_recent') }}
                @endif
            </p>
        </div>
        @if (! $isSearchMode)
            <div class="flex items-center gap-2">
                <flux:radio.group wire:model.live="currency" variant="segmented" aria-label="{{ Lang::get('ledger::list.currency_aria') }}">
                    <flux:radio value="eur" label="{{ Lang::get('ledger::list.currency_eur') }}" />
                    <flux:radio value="original" label="{{ Lang::get('ledger::list.currency_original') }}" />
                </flux:radio.group>
                <button
                    type="button"
                    wire:click="toggleFullHistory"
                    class="inline-flex items-center rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                >
                    {{ $fullHistory ? Lang::get('ledger::list.show_recent') : Lang::get('ledger::list.show_full') }}
                </button>
            </div>
        @endif
    </header>

    @if ($isSearchMode && count($page->rows) === 0 && $searchTotalCount === 0)
        {{-- No-results state (D-21) --}}
        @include('ledger::livewire.partials.search-no-results')
    @elseif (! $isSearchMode && count($page->rows) === 0)
        <p class="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500 dark:bg-slate-950 dark:text-slate-400 dark:border-slate-700">
            {{ Lang::get('ledger::list.empty_period') }}
        </p>
    @else
        {{-- ============================================================
             PHONE card-list (visible only at <768px)
             CSS hides this div at >=768px via `display:none`.
             Each card links to the transaction detail page (D-08).
             Iterates $accumulatedRows (the serialised scalar projection
             that TransactionsList accumulates across loadMore calls)
             rather than $page->rows so rows APPEND on scroll instead
             of replacing the visible page. Money is reconstructed from
             the minor+currency pair via the $rowMoney helper above.
             In search mode: snippet rendered as a second line (D-30).
             ============================================================ --}}
        <div class="md:hidden">
            <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                @foreach ($accumulatedRows as $row)
                    @php
                        $rowAmt = $rowMoney($row);
                        $rowLegs = $row['splitLegs'] ?? [];
                        $isSplitRow = count($rowLegs) >= 2;
                    @endphp
                    <div x-data="{ open: false }">
                        <div
                            class="card-list-item group {{ $isSearchMode ? 'srch-row' : '' }}"
                            data-testid="tx-card-{{ $row['id'] }}"
                            style="display: flex; align-items: center;"
                        >
                            <a
                                href="{{ route('transactions.show', ['transactionId' => $row['id']]) }}"
                                wire:navigate
                                class="block min-w-0 flex-1"
                            >
                                <div class="min-w-0 flex-1">
                                    {{-- Primary: counterparty name (2-line truncate) --}}
                                    <p class="primary line-clamp-2">{{ $row['counterpartyName'] ?? '—' }}</p>
                                    {{-- Search snippet second line (D-30 — phone two-line snippet rows) --}}
                                    @if ($isSearchMode && isset($searchRows[$row['id']]))
                                        @php $sRow = $searchRows[$row['id']]; @endphp
                                        @if ($sRow->snippet !== null)
                                            <p class="srch-snippet">{!! $sRow->snippet !!}</p>
                                        @endif
                                    @endif
                                    {{-- Secondary: category chip + posted date. The mini "cat"
                                         chip is suppressed for split parents — the split badge
                                         (outside this <a>, below) replaces it (UI-SPEC §5.1). --}}
                                    <p class="secondary mt-0.5 truncate">
                                        @if (! $isSplitRow && $row['categoryId'] !== null)
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">cat</span>
                                        @endif
                                        {{ $row['bookedAt'] }}
                                    </p>
                                </div>
                            </a>
                            {{-- Split badge: OUTSIDE the <a> (flex sibling) so toggling the
                                 legs never triggers navigation (UI-SPEC §5.1). No Livewire
                                 round trip — legs are already server-rendered below. --}}
                            @if ($isSplitRow)
                                <button
                                    type="button"
                                    class="split-badge"
                                    @click="open = !open"
                                    :aria-expanded="open"
                                    aria-controls="split-legs-phone-{{ $row['id'] }}"
                                    aria-label="{{ Lang::get('ledger::list.split_expand_aria', ['count' => count($rowLegs)]) }}"
                                    data-testid="split-badge-phone-{{ $row['id'] }}"
                                >
                                    {{ Lang::get('ledger::list.split_badge', ['count' => count($rowLegs)]) }}
                                    <svg class="split-badge__chevron h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </button>
                            @endif
                            {{-- Tax badge: always-visible at phone width (D-21). Parent-row
                                 badge unaffected by split state (UI-SPEC discretion — see the
                                 per-leg read-only badges in the expanded list below). --}}
                            <x-tax::tax-badge :transaction="$row" :showAlways="true" />
                            {{-- Cleared/uncleared/reconciled badge (SC-1, D-11). Always visible
                                 at phone width, same as the tax badge. --}}
                            <x-ledger::cleared-badge :transaction="['id' => $row['id'], 'status' => $row['status'] ?? \Modules\Ledger\Public\Enums\ClearedStatus::Cleared->value]" />
                            {{-- Amount: tabular, right-aligned; positive = emerald. Always the
                                 parent total — never a client-recomputed sum (UI-SPEC §5.1). --}}
                            <span class="amount {{ $isPositive($rowAmt) ? 'positive' : '' }}">
                                {{ $fmt($rowAmt) }}
                            </span>
                        </div>
                        {{-- Expanded legs: inset stacked rows below the card (UI-SPEC §5.2/§6/§14).
                             Server-rendered always; visibility is a pure Alpine toggle — no
                             Livewire round trip. Read-only: editing only happens on
                             TransactionDetail. --}}
                        @if ($isSplitRow)
                            <div id="split-legs-phone-{{ $row['id'] }}" x-show="open">
                                @foreach ($rowLegs as $leg)
                                    @php $legAmt = Money::ofMinor($leg['amountMinor'], $leg['amountCurrency']); @endphp
                                    <div
                                        class="split-leg-subrow"
                                        style="min-height: 40px; padding: var(--space-2) var(--space-4);"
                                        data-testid="split-leg-phone-{{ $row['id'] }}-{{ $leg['id'] }}"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs text-slate-900 dark:text-slate-100">{{ $leg['categoryName'] }}</span>
                                            <span class="text-xs {{ $isPositive($legAmt) ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">
                                                {{ $fmt($legAmt) }}
                                            </span>
                                        </div>
                                        <div class="mt-0.5 flex items-center justify-between gap-2">
                                            @if ($leg['note'] !== null && $leg['note'] !== '')
                                                <span class="text-xs italic text-slate-500 dark:text-slate-400">{{ $leg['note'] }}</span>
                                            @else
                                                <span></span>
                                            @endif
                                            @if ($leg['taxTagged'])
                                                <x-tax::tax-badge
                                                    :transaction="['id' => $row['id'], 'taxTagged' => true, 'taxCategoryShortName' => $leg['taxCategoryShortName']]"
                                                    :showAlways="true"
                                                    :readonly="true"
                                                />
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Infinite-scroll sentinel (D-09 / Pitfall 5).
                 Rendered inside this phone-only wrapper so the
                 IntersectionObserver never fires at desktop width.
                 wire:intersect fires loadMore when this element enters
                 the viewport (Livewire 4 bundled directive).

                 Re-keyed on $nextCursorId so Livewire 4 mounts a FRESH
                 sentinel node after each morph — the IntersectionObserver
                 re-binds to the new node and can fire again for the next
                 cursor. Without the re-key the observer latches to the
                 first sentinel element and goes dead after the first load.
                 No `.once` modifier so the observer can re-arm each time.
                 rootMargin="0px 0px 200px 0px" provides an early-trigger
                 buffer so the next page begins loading before the user
                 reaches the absolute bottom. --}}
            @if ($hasMore && $nextCursorId !== null)
                <div
                    wire:key="sentinel-{{ $nextCursorId }}"
                    wire:intersect.margin.0px.0px.200px.0px="loadMore"
                    class="flex justify-center py-4"
                    aria-hidden="true"
                ></div>

                {{-- Loading pulse shown while the loadMore request is in flight --}}
                <div wire:loading wire:target="loadMore" class="flex justify-center py-2">
                    <span class="dot-live" aria-label="{{ Lang::get('ledger::list.loading_more') }}"></span>
                </div>
            @endif
        </div>

        {{-- ============================================================
             DESKTOP table (visible only at >=768px)
             CSS hides this div at <768px via `display:none`.
             In search mode: counterparty cell uses {!! !!} for server-
             built FTS highlight markup (T-08-09 security boundary).
             Snippet rendered as a second line beneath counterparty.
             ============================================================ --}}
        <div class="hidden md:block">
            <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-900">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::list.table.date') }}</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::list.table.counterparty') }}</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::list.table.category') }}</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::list.table.tax') }}</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::list.table.status') }}</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::list.table.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700"
                        x-data="{ splitOpen: {} }"
                    >
                        @foreach ($page->rows as $row)
                            @php
                                $rowTaxState = $taxState[$row->id] ?? ['taxTagged' => false, 'taxCategoryShortName' => null];
                                $rowArr = ['id' => $row->id, 'taxTagged' => $rowTaxState['taxTagged'], 'taxCategoryShortName' => $rowTaxState['taxCategoryShortName']];
                                $isSearchRow = $isSearchMode && isset($searchRows[$row->id]);
                                $sRow = $isSearchRow ? $searchRows[$row->id] : null;
                                $rowLegs = $splitLegs[$row->id] ?? [];
                                $isSplitRow = count($rowLegs) >= 2;
                            @endphp
                            <tr class="group {{ $isSearchMode ? 'srch-row' : '' }}">
                                <td class="px-4 py-2 text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                    <a
                                        href="{{ route('transactions.show', ['transactionId' => $row->id]) }}"
                                        wire:navigate
                                        class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                        data-testid="tx-row-link-{{ $row->id }}"
                                    >{{ $row->bookedAt }}</a>
                                </td>
                                <td class="px-4 py-2 text-slate-900 dark:text-slate-100">
                                    {{-- In search mode: use {!! !!} ONLY for server-built FTS
                                         highlight() markup — never for raw user input (T-08-09). --}}
                                    @if ($isSearchRow && $sRow !== null && $sRow->highlightedCounterparty !== null)
                                        @if ($row->counterpartySlug !== null)
                                            <a
                                                href="{{ route('counterparties.profile', ['slug' => $row->counterpartySlug]) }}"
                                                wire:navigate
                                                class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                                data-testid="tx-row-counterparty-link-{{ $row->id }}"
                                            >{!! $sRow->highlightedCounterparty !!}</a>
                                        @else
                                            <span data-testid="tx-row-counterparty-text-{{ $row->id }}">{!! $sRow->highlightedCounterparty !!}</span>
                                        @endif
                                        @if ($sRow->snippet !== null)
                                            <p class="srch-snippet">{!! $sRow->snippet !!}</p>
                                        @endif
                                    @else
                                        @if ($row->counterpartySlug !== null)
                                            <a
                                                href="{{ route('counterparties.profile', ['slug' => $row->counterpartySlug]) }}"
                                                wire:navigate
                                                class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                                data-testid="tx-row-counterparty-link-{{ $row->id }}"
                                            >{{ $row->counterpartyName ?? '—' }}</a>
                                        @else
                                            <span data-testid="tx-row-counterparty-text-{{ $row->id }}">{{ $row->counterpartyName ?? '—' }}</span>
                                        @endif
                                        @if (isset(($chainTxIds ?? [])[$row->id]))
                                            <span
                                                class="ml-1.5 inline-flex items-center gap-0.5 text-[10px] font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-400"
                                                title="{{ Lang::get('ledger::list.chain_title') }}"
                                                data-testid="tx-row-chain-badge-{{ $row->id }}"
                                            >
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 015.656 5.656l-3 3a4 4 0 01-5.656-5.656M10.172 13.828a4 4 0 01-5.656-5.656l3-3a4 4 0 015.656 5.656"/>
                                                </svg>
                                                {{ Lang::get('ledger::list.chain_badge') }}
                                            </span>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-slate-500 dark:text-slate-400">
                                    @if ($isSplitRow)
                                        {{-- Split badge REPLACES the InlineCategoryPicker for split
                                             parents (D-01/D-11, UI-SPEC §5.1). No Livewire round
                                             trip — legs are already server-rendered below;
                                             visibility is a pure Alpine toggle. --}}
                                        <button
                                            type="button"
                                            class="split-badge"
                                            @click="splitOpen[{{ $row->id }}] = !splitOpen[{{ $row->id }}]"
                                            :aria-expanded="!!splitOpen[{{ $row->id }}]"
                                            aria-controls="split-legs-{{ $row->id }}"
                                            aria-label="{{ Lang::get('ledger::list.split_expand_aria', ['count' => count($rowLegs)]) }}"
                                            data-testid="split-badge-{{ $row->id }}"
                                        >
                                            {{ Lang::get('ledger::list.split_badge', ['count' => count($rowLegs)]) }}
                                            <svg class="split-badge__chevron h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </button>
                                    @else
                                        @livewire(
                                            'categorization.inline-category-picker',
                                            ['transactionId' => $row->id, 'categoryId' => $row->categoryId],
                                            key('cat-picker-' . $row->id)
                                        )
                                    @endif
                                </td>
                                {{-- Tax badge: hover-reveal on desktop (D-19/D-20). Unchanged on
                                     search rows AND on split parents (UI-SPEC discretion — see
                                     the per-leg read-only badges in the expanded sub-rows). --}}
                                <td class="px-4 py-2">
                                    <x-tax::tax-badge :transaction="$rowArr" :showAlways="false" />
                                </td>
                                {{-- Cleared/uncleared/reconciled badge (SC-1, D-11). Batch-loaded
                                     via $clearedState — no N+1 (Pitfall 1). --}}
                                <td class="px-4 py-2">
                                    <x-ledger::cleared-badge :transaction="['id' => $row->id, 'status' => $clearedState[$row->id] ?? \Modules\Ledger\Public\Enums\ClearedStatus::Cleared->value]" />
                                </td>
                                <td class="px-4 py-2 text-right" style="font-variant-numeric: tabular-nums;">
                                    @if ($isSearchMode)
                                        <span class="block text-sm text-slate-900 dark:text-slate-100">
                                            {{ Money::ofMinor($row->amountMinor, $row->amountCurrency)->format($row->amountCurrency === 'EUR' ? 'nl_NL' : 'en_US') }}
                                        </span>
                                    @else
                                        {{-- Always the parent total — never a client-recomputed
                                             leg sum (UI-SPEC §5.1). --}}
                                        <span class="block text-sm text-slate-900 dark:text-slate-100">{{ $fmt($row->amount) }}</span>
                                        @if ($currency === 'original' && $row->secondaryAmount !== null)
                                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $fmt($row->secondaryAmount) }}</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                            {{-- Expanded legs (UI-SPEC §5.2/§6): always server-rendered;
                                 visibility toggled client-side only via the shared
                                 $data.splitOpen map on the <tbody> host — no Livewire round
                                 trip. Legs render in creation order (sort_order). Read-only:
                                 editing only happens on TransactionDetail. --}}
                            @if ($isSplitRow)
                                @foreach ($rowLegs as $leg)
                                    <tr
                                        class="split-leg-subrow"
                                        @if ($loop->first) id="split-legs-{{ $row->id }}" @endif
                                        x-show="!!splitOpen[{{ $row->id }}]"
                                        data-testid="split-leg-{{ $row->id }}-{{ $leg['id'] }}"
                                    >
                                        <td class="px-4 py-2"></td>
                                        <td class="px-4 py-2 text-xs italic text-slate-500 dark:text-slate-400">{{ $leg['note'] ?? '' }}</td>
                                        <td class="px-4 py-2 text-xs text-slate-900 dark:text-slate-100">{{ $leg['categoryName'] }}</td>
                                        <td class="px-4 py-2">
                                            @if ($leg['taxTagged'])
                                                <x-tax::tax-badge
                                                    :transaction="['id' => $row->id, 'taxTagged' => true, 'taxCategoryShortName' => $leg['taxCategoryShortName']]"
                                                    :showAlways="false"
                                                    :readonly="true"
                                                />
                                            @endif
                                        </td>
                                        <td class="px-4 py-2"></td>
                                        <td class="px-4 py-2 text-right text-xs text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                                            {{ $fmt(Money::ofMinor($leg['amountMinor'], $leg['amountCurrency'])) }}
                                        </td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($page->hasMore && $page->nextCursorId !== null)
                <div class="flex justify-center">
                    <button
                        type="button"
                        wire:click="loadMore"
                        class="inline-flex items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    >{{ Lang::get('ledger::list.load_more') }}</button>
                </div>
            @endif
        </div>
    @endif
</div>
