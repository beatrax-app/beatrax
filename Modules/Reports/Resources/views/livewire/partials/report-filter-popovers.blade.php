{{--
    Report builder filter chips (D-04 — reused Search filter language and
    `.srch-*` CSS verbatim from Modules/Ledger/Resources/views/livewire/
    partials/search-filter-popovers.blade.php). Account / Category /
    Counterparty / Amount — the date dimension is covered by the Period
    preset chips above, not duplicated here.

    Variables in scope: $availableAccounts, $availableCategories,
    $availableCounterparties (list<array{id:int,name:string,...}>),
    $filterAccounts, $filterCategories, $filterCounterparties (list<int>),
    $filterAmountMin, $filterAmountMax, $filterAmountDir (string).
--}}

{{-- ─── Account chip ───────────────────────────────────────────────────── --}}
@if (! empty($availableAccounts ?? []))
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        <button
            type="button"
            x-on:click="open = !open"
            class="srch-chip {{ ! empty($filterAccounts ?? []) ? 'srch-chip--active' : '' }}"
            :aria-expanded="open"
        >
            @if (! empty($filterAccounts ?? []))
                @php
                    $acctCount = count($filterAccounts);
                    $acctLabel = $acctCount === 1
                        ? (collect($availableAccounts)->firstWhere('id', (int) $filterAccounts[0])['name'] ?? "{$acctCount} account")
                        : "{$acctCount} accounts";
                @endphp
                {{ $acctLabel }}
                <span
                    wire:click.stop="$set('filterAccounts', [])"
                    class="srch-chip-close"
                    role="button"
                    tabindex="0"
                    aria-label="Remove account filter"
                    x-on:keydown.enter.stop="$wire.$set('filterAccounts', [])"
                    x-on:keydown.space.prevent.stop="$wire.$set('filterAccounts', [])"
                >&times;</span>
            @else
                Account &#9662;
            @endif
        </button>
        <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="Account filter">
            <div class="srch-popover-inner">
                @foreach ($availableAccounts as $account)
                    <label class="srch-check-row">
                        <input type="checkbox" wire:model.live="filterAccounts" value="{{ $account['id'] }}" class="srch-checkbox" />
                        <span>{{ $account['name'] }}</span>
                        <span class="srch-chip-meta">{{ $account['currency'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- ─── Category chip ──────────────────────────────────────────────────── --}}
@if (! empty($availableCategories ?? []))
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        <button
            type="button"
            x-on:click="open = !open"
            class="srch-chip {{ ! empty($filterCategories ?? []) ? 'srch-chip--active' : '' }}"
            :aria-expanded="open"
        >
            @if (! empty($filterCategories ?? []))
                @php
                    $catCount = count($filterCategories);
                    $catLabel = $catCount === 1
                        ? (collect($availableCategories)->firstWhere('id', (int) $filterCategories[0])['name'] ?? "{$catCount} category")
                        : "{$catCount} categories";
                @endphp
                {{ $catLabel }}
                <span
                    wire:click.stop="$set('filterCategories', [])"
                    class="srch-chip-close"
                    role="button"
                    tabindex="0"
                    aria-label="Remove category filter"
                    x-on:keydown.enter.stop="$wire.$set('filterCategories', [])"
                    x-on:keydown.space.prevent.stop="$wire.$set('filterCategories', [])"
                >&times;</span>
            @else
                Category &#9662;
            @endif
        </button>
        <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="Category filter">
            <div class="srch-popover-inner">
                @foreach ($availableCategories as $category)
                    <label class="srch-check-row">
                        <input type="checkbox" wire:model.live="filterCategories" value="{{ $category['id'] }}" class="srch-checkbox" />
                        <span>{{ $category['name'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- ─── Counterparty chip (999.6-02 — new dimension, no prior UI precedent) ── --}}
@if (! empty($availableCounterparties ?? []))
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        <button
            type="button"
            x-on:click="open = !open"
            class="srch-chip {{ ! empty($filterCounterparties ?? []) ? 'srch-chip--active' : '' }}"
            :aria-expanded="open"
        >
            @if (! empty($filterCounterparties ?? []))
                @php
                    $cpCount = count($filterCounterparties);
                    $cpLabel = $cpCount === 1
                        ? (collect($availableCounterparties)->firstWhere('id', (int) $filterCounterparties[0])['name'] ?? "{$cpCount} counterparty")
                        : "{$cpCount} counterparties";
                @endphp
                {{ $cpLabel }}
                <span
                    wire:click.stop="$set('filterCounterparties', [])"
                    class="srch-chip-close"
                    role="button"
                    tabindex="0"
                    aria-label="Remove counterparty filter"
                    x-on:keydown.enter.stop="$wire.$set('filterCounterparties', [])"
                    x-on:keydown.space.prevent.stop="$wire.$set('filterCounterparties', [])"
                >&times;</span>
            @else
                Counterparty &#9662;
            @endif
        </button>
        <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="Counterparty filter">
            <div class="srch-popover-inner">
                @foreach ($availableCounterparties as $counterparty)
                    <label class="srch-check-row">
                        <input type="checkbox" wire:model.live="filterCounterparties" value="{{ $counterparty['id'] }}" class="srch-checkbox" />
                        <span>{{ $counterparty['name'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- ─── Amount chip ────────────────────────────────────────────────────── --}}
<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @php
        $amountActive = ($filterAmountMin ?? '') !== '' || ($filterAmountMax ?? '') !== '' || ($filterAmountDir ?? 'both') !== 'both';
        $amountLabel = 'Amount &#9662;';
        if ($amountActive) {
            if (($filterAmountDir ?? 'both') === 'in') {
                $amountLabel = 'In';
            } elseif (($filterAmountDir ?? 'both') === 'out') {
                $amountLabel = 'Out';
            }
            if (($filterAmountMin ?? '') !== '') {
                $amountLabel .= ' &gt; €'.number_format((float) ($filterAmountMin ?? 0), 2, ',', '.');
            }
            if (($filterAmountMax ?? '') !== '') {
                $amountLabel .= ' &lt; €'.number_format((float) ($filterAmountMax ?? 0), 2, ',', '.');
            }
        }
    @endphp
    <button type="button" x-on:click="open = !open" class="srch-chip {{ $amountActive ? 'srch-chip--active' : '' }}" :aria-expanded="open">
        {!! $amountLabel !!}
        @if ($amountActive)
            <span
                wire:click.stop="$set('filterAmountMin', ''); $set('filterAmountMax', ''); $set('filterAmountDir', 'both')"
                class="srch-chip-close"
                role="button"
                tabindex="0"
                aria-label="Remove amount filter"
                x-on:keydown.enter.stop="$wire.$set('filterAmountMin', ''); $wire.$set('filterAmountMax', ''); $wire.$set('filterAmountDir', 'both')"
                x-on:keydown.space.prevent.stop="$wire.$set('filterAmountMin', ''); $wire.$set('filterAmountMax', ''); $wire.$set('filterAmountDir', 'both')"
            >&times;</span>
        @endif
    </button>
    <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="Amount filter">
        <div class="srch-popover-inner">
            <div class="srch-dir-group mb-3">
                @foreach (['both' => 'Both', 'in' => 'In', 'out' => 'Out'] as $val => $lbl)
                    <label class="srch-radio-row">
                        <input type="radio" wire:model.live="filterAmountDir" value="{{ $val }}" class="srch-radio" />
                        <span>{{ $lbl }}</span>
                    </label>
                @endforeach
            </div>
            <div class="srch-amount-range">
                {{-- WR-01: debounced so rapid typing doesn't fire an overlapping Livewire round trip per keystroke (Livewire's own textbook race condition for un-debounced live text/number inputs). --}}
                <input type="number" wire:model.live.debounce.500ms="filterAmountMin" min="0" step="0.01" placeholder="Min" class="srch-amount-input" aria-label="Minimum amount" />
                <span class="srch-date-sep">–</span>
                <input type="number" wire:model.live.debounce.500ms="filterAmountMax" min="0" step="0.01" placeholder="Max" class="srch-amount-input" aria-label="Maximum amount" />
            </div>
        </div>
    </div>
</div>
