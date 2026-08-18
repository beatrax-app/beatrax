@use('Modules\Core\Public\Support\Lang')
{{--
    Search toolbar — always visible on /transactions once Phase 8 ships.

    Contains:
    - Search input (wire:model.live.debounce.250ms="searchQuery")
    - Filter chip row (Date / Account / Amount / Category) — desktop
    - Summary strip (.srch-strip) — visible when search is active
    - "Clear all" link — visible when search is active
    - Phone: "Filters {N}" button + bottom sheet with stacked filter sections

    UI-SPEC bindings: § Component Inventory #1 (srch-toolbar), #2 (srch-strip),
    #4 (filter popovers), #5 (phone bottom sheet).
    Copywriting Contract: Search placeholder, chip copy, Clear all, Filters {N}.
--}}

<div class="srch-toolbar">
    {{-- ─── Search input ─────────────────────────────────────────────── --}}
    <div class="srch-input-wrap" wire:loading.class="srch-input-wrap--loading" wire:target="searchQuery">
        <span class="srch-icon" aria-hidden="true">⌕</span>
        <input
            type="search"
            wire:model.live.debounce.250ms="searchQuery"
            placeholder="{{ Lang::get('ledger::list.search.placeholder') }}"
            class="srch-input hidden md:block"
            aria-label="{{ Lang::get('ledger::list.search.aria') }}"
            x-on:keydown.escape="$wire.clearSearch()"
        />
        {{-- Phone-specific shorter placeholder --}}
        <input
            type="search"
            wire:model.live.debounce.250ms="searchQuery"
            placeholder="{{ Lang::get('ledger::list.search.placeholder_short') }}"
            class="srch-input md:hidden"
            aria-label="{{ Lang::get('ledger::list.search.aria') }}"
            x-on:keydown.escape="$wire.clearSearch()"
        />
        <span wire:loading wire:target="searchQuery" class="srch-spinner" aria-hidden="true">
            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </span>
    </div>

    {{-- ─── Filter chips row (desktop ≥768px) ──────────────────────────── --}}
    <div class="srch-chips hidden md:flex">
        @include('ledger::livewire.partials.search-filter-popovers')

        @if ($isSearchMode ?? false)
            <button
                type="button"
                wire:click="clearSearch"
                class="srch-clear-all ml-auto"
            >{{ Lang::get('ledger::list.search.clear_all') }}</button>
        @endif
    </div>

    {{-- ─── Phone "Filters {N}" button + bottom sheet (<768px) ────────────── --}}
    <div class="md:hidden flex items-center gap-2 mt-2">
        <x-core::bottom-sheet name="search-filters" title="{{ Lang::get('ledger::list.search.filters') }}">
            <button
                type="button"
                x-on:click="$dispatch('open-sheet', { name: 'search-filters' })"
                class="srch-filters-btn"
                aria-label="{{ Lang::get('ledger::list.search.open_filters_aria') }}"
            >
                {{ Lang::get('ledger::list.search.filters') }}
                @if (($activeFilterCount ?? 0) > 0)
                    <span class="srch-filter-badge">{{ $activeFilterCount }}</span>
                @endif
            </button>

            {{-- Bottom sheet slot content (stacked filter sections) --}}
            <div class="space-y-4">
                {{-- Date filter --}}
                <div>
                    <p class="srch-sheet-section-label">{{ Lang::get('ledger::list.filter.date_range') }}</p>
                    <div class="srch-sheet-section">
                        <div class="srch-date-presets">
                            @foreach ([
                                'this_month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
                                'last_month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()],
                                'this_year'  => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
                                'last_year'  => [now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString()],
                            ] as $presetKey => [$start, $end])
                                <button
                                    type="button"
                                    wire:click="$set('filterAfter', '{{ $start }}')"
                                    x-on:click="$wire.$set('filterBefore', '{{ $end }}')"
                                    class="srch-date-preset"
                                >{{ Lang::get('ledger::list.date_preset.'.$presetKey) }}</button>
                            @endforeach
                        </div>
                        <div class="srch-date-range">
                            <x-core::date-input wire:model.live="filterAfter" :aria-label="Lang::get('ledger::list.filter.after_aria')" />
                            <span class="srch-date-sep">–</span>
                            <x-core::date-input wire:model.live="filterBefore" :aria-label="Lang::get('ledger::list.filter.before_aria')" />
                        </div>
                    </div>
                </div>

                {{-- Account filter --}}
                @if (! empty($availableAccounts))
                    <div>
                        <p class="srch-sheet-section-label">{{ Lang::get('ledger::list.filter.account') }}</p>
                        <div class="srch-sheet-section">
                            @foreach ($availableAccounts as $account)
                                <label class="srch-check-row">
                                    <input
                                        type="checkbox"
                                        wire:model.live="filterAccounts"
                                        value="{{ $account['id'] }}"
                                        class="srch-checkbox"
                                    />
                                    <span>{{ $account['name'] }}</span>
                                    <span class="srch-chip-meta">{{ $account['currency'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Amount filter --}}
                <div>
                    <p class="srch-sheet-section-label">{{ Lang::get('ledger::list.filter.amount') }}</p>
                    <div class="srch-sheet-section">
                        <div class="srch-dir-group">
                            @foreach (['both' => 'dir_both', 'in' => 'dir_in', 'out' => 'dir_out'] as $val => $dirKey)
                                <label class="srch-radio-row">
                                    <input type="radio" wire:model.live="filterAmountDir" value="{{ $val }}" class="srch-radio" />
                                    <span>{{ Lang::get('ledger::list.filter.'.$dirKey) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="srch-amount-range">
                            <input type="number" wire:model.live="filterAmountMin" min="0" step="0.01" placeholder="{{ Lang::get('ledger::list.filter.min') }}" class="srch-amount-input" aria-label="{{ Lang::get('ledger::list.filter.min_aria') }}" />
                            <span class="srch-date-sep">–</span>
                            <input type="number" wire:model.live="filterAmountMax" min="0" step="0.01" placeholder="{{ Lang::get('ledger::list.filter.max') }}" class="srch-amount-input" aria-label="{{ Lang::get('ledger::list.filter.max_aria') }}" />
                        </div>
                    </div>
                </div>

                {{-- Category filter --}}
                @if (! empty($availableCategories))
                    <div>
                        <p class="srch-sheet-section-label">{{ Lang::get('ledger::list.filter.category') }}</p>
                        <div class="srch-sheet-section">
                            @foreach ($availableCategories as $category)
                                <label class="srch-check-row">
                                    <input
                                        type="checkbox"
                                        wire:model.live="filterCategories"
                                        value="{{ $category['id'] }}"
                                        class="srch-checkbox"
                                    />
                                    <span>{{ $category['name'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Apply / Clear footer --}}
            <div class="srch-sheet-footer">
                <button
                    type="button"
                    x-on:click="open = false"
                    class="srch-sheet-apply"
                >{{ Lang::get('ledger::list.search.apply') }}</button>
                <button
                    type="button"
                    wire:click="clearSearch"
                    x-on:click="open = false"
                    class="srch-sheet-clear"
                >{{ Lang::get('ledger::list.search.clear') }}</button>
            </div>
        </x-core::bottom-sheet>

        @if ($isSearchMode ?? false)
            <button type="button" wire:click="clearSearch" class="srch-clear-all">{{ Lang::get('ledger::list.search.clear_all') }}</button>
        @endif
    </div>

    {{-- ─── Summary strip (.srch-strip) — visible when search is active ── --}}
    @if ($isSearchMode ?? false)
        @php
            $countLabel = Lang::choice('ledger::list.search.count', $searchTotalCount, ['count' => $searchTotalCount]);
            $flow = Lang::get('ledger::list.search.flow', [
                'out' => $fmtMinor($searchTotalOut),
                'in' => $fmtMinor($searchTotalIn),
            ]);
        @endphp
        <div class="srch-strip" aria-live="polite">
            @if ($searchQuery !== '')
                {{ $countLabel }}
                &middot; {{ $flow }}
            @else
                {{ $countLabel }} {{ Lang::get('ledger::list.search.matching_suffix') }}
                &middot; {{ $flow }}
            @endif
        </div>
    @endif
</div>
