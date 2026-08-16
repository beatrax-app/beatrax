@use('Modules\Core\Public\Support\Lang')
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
        <span class="srch-chip {{ ! empty($filterAccounts ?? []) ? 'srch-chip--active' : '' }}">
            <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
                @if (! empty($filterAccounts ?? []))
                    @php
                        $acctCount = count($filterAccounts);
                        $acctCounted = Lang::choice('reports::builder.filter.account_count', $acctCount, ['count' => $acctCount]);
                        $acctLabel = $acctCount === 1
                            ? (collect($availableAccounts)->firstWhere('id', (int) $filterAccounts[0])['name'] ?? $acctCounted)
                            : $acctCounted;
                    @endphp
                    {{ $acctLabel }}
                @else
                    {{ Lang::get('reports::builder.filter.account') }} &#9662;
                @endif
            </button>
            @if (! empty($filterAccounts ?? []))
                <button
                    type="button"
                    wire:click.stop="$set('filterAccounts', [])"
                    class="srch-chip-close"
                    aria-label="{{ Lang::get('reports::builder.filter.remove_account') }}"
                >&times;</button>
            @endif
        </span>
        <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="{{ Lang::get('reports::builder.filter.account_dialog') }}">
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
        <span class="srch-chip {{ ! empty($filterCategories ?? []) ? 'srch-chip--active' : '' }}">
            <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
                @if (! empty($filterCategories ?? []))
                    @php
                        $catCount = count($filterCategories);
                        $catCounted = Lang::choice('reports::builder.filter.category_count', $catCount, ['count' => $catCount]);
                        $catLabel = $catCount === 1
                            ? (collect($availableCategories)->firstWhere('id', (int) $filterCategories[0])['name'] ?? $catCounted)
                            : $catCounted;
                    @endphp
                    {{ $catLabel }}
                @else
                    {{ Lang::get('reports::builder.filter.category') }} &#9662;
                @endif
            </button>
            @if (! empty($filterCategories ?? []))
                <button
                    type="button"
                    wire:click.stop="$set('filterCategories', [])"
                    class="srch-chip-close"
                    aria-label="{{ Lang::get('reports::builder.filter.remove_category') }}"
                >&times;</button>
            @endif
        </span>
        <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="{{ Lang::get('reports::builder.filter.category_dialog') }}">
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
        <span class="srch-chip {{ ! empty($filterCounterparties ?? []) ? 'srch-chip--active' : '' }}">
            <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
                @if (! empty($filterCounterparties ?? []))
                    @php
                        $cpCount = count($filterCounterparties);
                        $cpCounted = Lang::choice('reports::builder.filter.counterparty_count', $cpCount, ['count' => $cpCount]);
                        $cpLabel = $cpCount === 1
                            ? (collect($availableCounterparties)->firstWhere('id', (int) $filterCounterparties[0])['name'] ?? $cpCounted)
                            : $cpCounted;
                    @endphp
                    {{ $cpLabel }}
                @else
                    {{ Lang::get('reports::builder.filter.counterparty') }} &#9662;
                @endif
            </button>
            @if (! empty($filterCounterparties ?? []))
                <button
                    type="button"
                    wire:click.stop="$set('filterCounterparties', [])"
                    class="srch-chip-close"
                    aria-label="{{ Lang::get('reports::builder.filter.remove_counterparty') }}"
                >&times;</button>
            @endif
        </span>
        <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="{{ Lang::get('reports::builder.filter.counterparty_dialog') }}">
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
        $amountLabel = Lang::get('reports::builder.filter.amount').' &#9662;';
        if ($amountActive) {
            if (($filterAmountDir ?? 'both') === 'in') {
                $amountLabel = Lang::get('reports::builder.filter.dir_in');
            } elseif (($filterAmountDir ?? 'both') === 'out') {
                $amountLabel = Lang::get('reports::builder.filter.dir_out');
            }
            if (($filterAmountMin ?? '') !== '') {
                $amountLabel .= ' &gt; €'.number_format((float) ($filterAmountMin ?? 0), 2, ',', '.');
            }
            if (($filterAmountMax ?? '') !== '') {
                $amountLabel .= ' &lt; €'.number_format((float) ($filterAmountMax ?? 0), 2, ',', '.');
            }
        }
    @endphp
    <span class="srch-chip {{ $amountActive ? 'srch-chip--active' : '' }}">
        <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
            {!! $amountLabel !!}
        </button>
        @if ($amountActive)
            <button
                type="button"
                wire:click.stop="$set('filterAmountMin', ''); $set('filterAmountMax', ''); $set('filterAmountDir', 'both')"
                class="srch-chip-close"
                aria-label="{{ Lang::get('reports::builder.filter.remove_amount') }}"
            >&times;</button>
        @endif
    </span>
    <div x-show="open" x-on:click.outside="open = false" x-transition class="srch-popover" role="dialog" aria-label="{{ Lang::get('reports::builder.filter.amount_dialog') }}">
        <div class="srch-popover-inner">
            <div class="srch-dir-group mb-3">
                @foreach (['both' => Lang::get('reports::builder.filter.dir_both'), 'in' => Lang::get('reports::builder.filter.dir_in'), 'out' => Lang::get('reports::builder.filter.dir_out')] as $val => $lbl)
                    <label class="srch-radio-row">
                        <input type="radio" wire:model.live="filterAmountDir" value="{{ $val }}" class="srch-radio" />
                        <span>{{ $lbl }}</span>
                    </label>
                @endforeach
            </div>
            <div class="srch-amount-range">
                {{-- WR-01: debounced so rapid typing doesn't fire an overlapping Livewire round trip per keystroke (Livewire's own textbook race condition for un-debounced live text/number inputs). --}}
                <input type="number" wire:model.live.debounce.500ms="filterAmountMin" min="0" step="0.01" placeholder="{{ Lang::get('reports::builder.filter.min') }}" class="srch-amount-input" aria-label="{{ Lang::get('reports::builder.filter.min_aria') }}" />
                <span class="srch-date-sep">–</span>
                <input type="number" wire:model.live.debounce.500ms="filterAmountMax" min="0" step="0.01" placeholder="{{ Lang::get('reports::builder.filter.max') }}" class="srch-amount-input" aria-label="{{ Lang::get('reports::builder.filter.max_aria') }}" />
            </div>
        </div>
    </div>
</div>
