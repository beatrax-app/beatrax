@use('Modules\Core\Public\Support\Lang')
{{--
    Filter chip popovers — desktop (≥768px) only.
    Each chip is an Alpine.js-driven popover (no Flux dependency) so the
    component renders correctly in test environments without published Flux stubs.

    UI-SPEC: Component Inventory #4 (filter popovers).
    Copywriting Contract: chip copy in inactive and active states.

    Consumed by search-toolbar.blade.php (inside the .srch-chips flex row).
--}}

{{-- ─── Date chip ─────────────────────────────────────────────────────── --}}
<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <span class="srch-chip {{ ($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '' ? 'srch-chip--active' : '' }}">
        <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
            @if (($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '')
                @php
                    $dateLabel = match (true) {
                        ($filterAfter ?? '') !== '' && ($filterBefore ?? '') !== '' => Lang::get('ledger::list.filter.custom_range'),
                        ($filterAfter ?? '') !== '' => Lang::get('ledger::list.filter.after', ['date' => $filterAfter ?? '']),
                        default => Lang::get('ledger::list.filter.before', ['date' => $filterBefore ?? '']),
                    };
                @endphp
                {{ $dateLabel }}
            @else
                {{ Lang::get('ledger::list.filter.date') }} &#9662;
            @endif
        </button>
        @if (($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '')
            <button
                type="button"
                wire:click.stop="$set('filterAfter', ''); $set('filterBefore', '')"
                class="srch-chip-close"
                aria-label="{{ Lang::get('ledger::list.filter.remove_date_aria') }}"
            >&times;</button>
        @endif
    </span>
    <div
        x-show="open"
        x-on:click.outside="open = false"
        x-transition
        class="srch-popover"
        role="dialog"
        aria-label="{{ Lang::get('ledger::list.filter.date_dialog') }}"
    >
        <div class="srch-popover-inner">
            {{-- Preset buttons --}}
            @foreach ([
                'this_month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
                'last_month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()],
                'this_year'  => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
                'last_year'  => [now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString()],
            ] as $presetKey => [$start, $end])
                <button
                    type="button"
                    wire:click="$set('filterAfter', '{{ $start }}')"
                    x-on:click="$wire.$set('filterBefore', '{{ $end }}'); open = false"
                    class="srch-date-preset"
                >{{ Lang::get('ledger::list.date_preset.'.$presetKey) }}</button>
            @endforeach

            <div class="srch-date-range mt-2">
                {{-- for/id rather than aria-label on the input: the label was
                     rendered but attached to nothing, and an aria-label that
                     does not contain the visible text gives the field a name
                     no one can say out loud to a user looking at "From". --}}
                <label for="search-filter-after" class="srch-filter-label">{{ Lang::get('ledger::list.filter.from') }}</label>
                <input id="search-filter-after" type="date" wire:model.live="filterAfter" class="srch-date-input" />
                <label for="search-filter-before" class="srch-filter-label mt-1">{{ Lang::get('ledger::list.filter.to') }}</label>
                <input id="search-filter-before" type="date" wire:model.live="filterBefore" class="srch-date-input" />
            </div>
        </div>
    </div>
</div>

{{-- ─── Account chip ───────────────────────────────────────────────────── --}}
@if (! empty($availableAccounts ?? []))
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        <span class="srch-chip {{ ! empty($filterAccounts ?? []) ? 'srch-chip--active' : '' }}">
            <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
                @if (! empty($filterAccounts ?? []))
                    @php
                        $acctCount = count($filterAccounts);
                        $acctLabel = $acctCount === 1
                            ? collect($availableAccounts)->firstWhere('id', (int) $filterAccounts[0])['name'] ?? Lang::get('ledger::list.filter.acct_one', ['count' => $acctCount])
                            : Lang::get('ledger::list.filter.acct_many', ['count' => $acctCount]);
                    @endphp
                    {{ $acctLabel }}
                @else
                    {{ Lang::get('ledger::list.filter.account') }} &#9662;
                @endif
            </button>
            @if (! empty($filterAccounts ?? []))
                <button
                    type="button"
                    wire:click.stop="$set('filterAccounts', [])"
                    class="srch-chip-close"
                    aria-label="{{ Lang::get('ledger::list.filter.remove_account_aria') }}"
                >&times;</button>
            @endif
        </span>
        <div
            x-show="open"
            x-on:click.outside="open = false"
            x-transition
            class="srch-popover"
            role="dialog"
            aria-label="{{ Lang::get('ledger::list.filter.account_dialog') }}"
        >
            <div class="srch-popover-inner">
                @foreach ($availableAccounts ?? [] as $account)
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
    </div>
@endif

{{-- ─── Amount chip ────────────────────────────────────────────────────── --}}
<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @php
        $amountActive = ($filterAmountMin ?? '') !== '' || ($filterAmountMax ?? '') !== '' || ($filterAmountDir ?? 'both') !== 'both';
        $amountLabel = Lang::get('ledger::list.filter.amount').' &#9662;';
        if ($amountActive) {
            if (($filterAmountDir ?? 'both') === 'in') {
                $amountLabel = Lang::get('ledger::list.filter.dir_in');
            } elseif (($filterAmountDir ?? 'both') === 'out') {
                $amountLabel = Lang::get('ledger::list.filter.dir_out');
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
                aria-label="{{ Lang::get('ledger::list.filter.remove_amount_aria') }}"
            >&times;</button>
        @endif
    </span>
    <div
        x-show="open"
        x-on:click.outside="open = false"
        x-transition
        class="srch-popover"
        role="dialog"
        aria-label="{{ Lang::get('ledger::list.filter.amount_dialog') }}"
    >
        <div class="srch-popover-inner">
            {{-- Direction (in / out / both) --}}
            <div class="srch-dir-group mb-3">
                @foreach (['both' => 'dir_both', 'in' => 'dir_in', 'out' => 'dir_out'] as $val => $dirKey)
                    <label class="srch-radio-row">
                        <input
                            type="radio"
                            wire:model.live="filterAmountDir"
                            value="{{ $val }}"
                            class="srch-radio"
                        />
                        <span>{{ Lang::get('ledger::list.filter.'.$dirKey) }}</span>
                    </label>
                @endforeach
            </div>
            <div class="srch-amount-range">
                <input
                    type="number"
                    wire:model.live="filterAmountMin"
                    min="0"
                    step="0.01"
                    placeholder="{{ Lang::get('ledger::list.filter.min') }}"
                    class="srch-amount-input"
                    aria-label="{{ Lang::get('ledger::list.filter.min_aria') }}"
                />
                <span class="srch-date-sep">–</span>
                <input
                    type="number"
                    wire:model.live="filterAmountMax"
                    min="0"
                    step="0.01"
                    placeholder="{{ Lang::get('ledger::list.filter.max') }}"
                    class="srch-amount-input"
                    aria-label="{{ Lang::get('ledger::list.filter.max_aria') }}"
                />
            </div>
        </div>
    </div>
</div>

{{-- ─── Category chip ──────────────────────────────────────────────────── --}}
@if (! empty($availableCategories ?? []))
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        <span class="srch-chip {{ ! empty($filterCategories ?? []) ? 'srch-chip--active' : '' }}">
            <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
                @if (! empty($filterCategories ?? []))
                    @php
                        $catCount = count($filterCategories);
                        $catLabel = $catCount === 1
                            ? collect($availableCategories)->firstWhere('id', (int) $filterCategories[0])['name'] ?? Lang::get('ledger::list.filter.cat_one', ['count' => $catCount])
                            : Lang::get('ledger::list.filter.cat_many', ['count' => $catCount]);
                    @endphp
                    {{ $catLabel }}
                @else
                    {{ Lang::get('ledger::list.filter.category') }} &#9662;
                @endif
            </button>
            @if (! empty($filterCategories ?? []))
                <button
                    type="button"
                    wire:click.stop="$set('filterCategories', [])"
                    class="srch-chip-close"
                    aria-label="{{ Lang::get('ledger::list.filter.remove_category_aria') }}"
                >&times;</button>
            @endif
        </span>
        <div
            x-show="open"
            x-on:click.outside="open = false"
            x-transition
            class="srch-popover"
            role="dialog"
            aria-label="{{ Lang::get('ledger::list.filter.category_dialog') }}"
        >
            <div class="srch-popover-inner">
                @foreach ($availableCategories ?? [] as $category)
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
    </div>
@endif
