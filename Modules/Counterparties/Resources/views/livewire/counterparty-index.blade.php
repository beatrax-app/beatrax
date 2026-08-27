@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
{{--
    /counterparties index — cards-default grid with type-filter chip
    row and the Cards / List view toggle persisted in
    user_preferences.counterparty_index_view.

    Consumes the 17-06a x-component <x-counterparties::type-chip>
    for the per-row type label; the chip row and the card body are
    inlined here because both carry wire: bindings.

    All copy is verbatim from 17-UI-SPEC.md (Counterparty index table).

    Variables exposed by `CounterpartyIndex::render()`:
      $rows         Illuminate\Support\Collection<CounterpartyIndexRow>
      $counts       array<string, int>    — chip counts keyed by filter value
      $activeFilter CounterpartyTypeFilter — currently selected filter chip
      $activeView   string                — 'cards' | 'list'
--}}
@use('Modules\Counterparties\Internal\Enums\CounterpartyTypeFilter')
@use('Modules\Counterparties\Public\Enums\CounterpartyType')
@php
    $totalEntities = $counts[CounterpartyTypeFilter::All->value] ?? 0;
    $unknownCount = $counts[CounterpartyTypeFilter::Unknown->value] ?? 0;
@endphp

<div class="space-y-8" style="padding: var(--space-6) var(--space-4); max-width: 1200px; margin: 0 auto;">
    {{-- Page head ------------------------------------------------- --}}
    <header class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight" style="color: var(--color-text);">
            {{ Lang::get('counterparties::index.heading') }}
        </h1>
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
            @if ($unknownCount > 0)
                {{ Lang::choice('counterparties::index.entities', $totalEntities) }} ·
                <a
                    href="{{ Destination::Triage->url() }}"
                    class="tap-link"
                    style="color: var(--color-amber); text-decoration: underline;"
                >{{ Lang::get('counterparties::index.need_identification', ['count' => $unknownCount]) }}</a>
            @else
                {{ Lang::choice('counterparties::index.entities', $totalEntities) }}
            @endif
        </p>
    </header>

    {{-- Toolbar: phone collapse vs. full desktop toolbar --------- --}}
    {{-- Phone (<768px): filter-sheet-trigger row only (search + Filters badge) --}}
    <div class="phone-only">
        <x-core::filter-sheet-trigger :activeCount="0" searchModel="" />
    </div>

    {{-- Desktop (>=768px): search · sort · view toggle inline --}}
    {{-- Outer .desktop-only controls visibility (none at <768px, block at >=768px). --}}
    {{-- Inner flex wrapper owns the layout so no inline display: leaks to phone width. --}}
    <div class="desktop-only">
        <div style="display: flex; align-items: center; gap: var(--space-4); flex-wrap: wrap;">
        <div class="side-search" role="search" style="flex: 1 1 280px; min-width: 240px;">
            <x-core::search-mark class="ic" />
            <input
                type="text"
                placeholder="{{ Lang::get('counterparties::index.search_placeholder') }}"
                aria-label="{{ Lang::get('counterparties::index.search_aria') }}"
                disabled
            />
            <span class="kbd" aria-hidden="true">/</span>
        </div>

        <span style="font-size: var(--text-sm); color: var(--color-text-muted);">
            {{ Lang::get('counterparties::index.sort') }}
        </span>

        <div class="view-toggle" role="group" aria-label="{{ Lang::get('counterparties::index.view_mode') }}">
            <button
                type="button"
                class="{{ $activeView === 'cards' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                aria-pressed="{{ $activeView === 'cards' ? 'true' : 'false' }}"
                wire:click="setView('cards')"
            >▦ {{ Lang::get('counterparties::index.view_cards') }}</button>
            <button
                type="button"
                class="{{ $activeView === 'list' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                aria-pressed="{{ $activeView === 'list' ? 'true' : 'false' }}"
                wire:click="setView('list')"
            >≡ {{ Lang::get('counterparties::index.view_list') }}</button>
        </div>
        </div>
    </div>

    {{-- Type-filter chip row -------------------------------------- --}}
    <div role="group" aria-label="{{ Lang::get('counterparties::index.filter_aria') }}" class="filter-chips">
        @php
            $chipDefs = [
                ['filter' => CounterpartyTypeFilter::All, 'label' => Lang::get('counterparties::index.chips.all'), 'dot' => null],
                ['filter' => CounterpartyTypeFilter::Merchant, 'label' => Lang::get('counterparties::index.chips.merchant'), 'dot' => 'dot-merchant'],
                ['filter' => CounterpartyTypeFilter::Personal, 'label' => Lang::get('counterparties::index.chips.personal'), 'dot' => 'dot-personal'],
                ['filter' => CounterpartyTypeFilter::Bank, 'label' => Lang::get('counterparties::index.chips.bank'), 'dot' => 'dot-bank'],
                ['filter' => CounterpartyTypeFilter::Government, 'label' => Lang::get('counterparties::index.chips.government'), 'dot' => 'dot-gov'],
                ['filter' => CounterpartyTypeFilter::SelfAccount, 'label' => Lang::get('counterparties::index.chips.self'), 'dot' => 'dot-self'],
                ['filter' => CounterpartyTypeFilter::Unknown, 'label' => Lang::get('counterparties::index.chips.unknown'), 'dot' => 'dot-unknown'],
            ];
        @endphp
        @foreach ($chipDefs as $chip)
            <button
                type="button"
                class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                aria-pressed="{{ $activeFilter === $chip['filter'] ? 'true' : 'false' }}"
                wire:click="setType('{{ $chip['filter']->value }}')"
            >
                @if ($chip['dot'] !== null)
                    <span class="chip-dot {{ $chip['dot'] }}" aria-hidden="true"></span>
                @endif
                <span>{{ $chip['label'] }}</span>
                <span class="chip-count">{{ $counts[$chip['filter']->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    {{-- Grid / list body ------------------------------------------ --}}
    @if ($rows->isEmpty())
        <x-core::empty-state
            :heading="Lang::get('counterparties::index.empty_heading')"
            :body="Lang::get('counterparties::index.empty_body')"
        >
            <a
                href="{{ Destination::Imports->url() }}"
                class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                style="display: inline-block;"
            >{{ Lang::get('counterparties::index.empty_cta') }}</a>
        </x-core::empty-state>
    @elseif ($activeView === 'cards')
        {{-- cards grid: single-column at phone width, auto-fill at >=768px --}}
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--space-5);"
             class="cp-cards-grid">
            @foreach ($rows as $row)
                @php
                    $isUnknown = $row->type === CounterpartyType::Unknown->value;
                    $isSelf = $row->type === CounterpartyType::SelfAccount->value;
                @endphp
                @if ($isSelf)
                    <a
                        href="{{ $row->href }}"
                        class="cp-card focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        data-self-row="true"
                    >
                        <header class="cp-head">
                            <span class="cp-head-name">{{ $row->displayName }}</span>
                            <x-counterparties::type-chip :type="$row->type" />
                        </header>
                        <div class="cp-recent">
                            <span>{{ Lang::get('counterparties::index.self_routing') }}</span>
                            <span>{{ Lang::get('counterparties::index.self_no_flow') }}</span>
                        </div>
                        <div style="font-size: var(--text-xs); color: var(--color-text-muted); margin-top: auto;">
                            {{ Lang::get('counterparties::index.self_open') }}
                        </div>
                    </a>
                @elseif ($isUnknown)
                    <a
                        href="{{ $row->href }}"
                        class="cp-card unknown focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                    >
                        <header class="cp-head">
                            <span class="cp-head-name">{{ $row->displayName }}</span>
                            <x-counterparties::type-chip :type="$row->type" />
                        </header>
                        <div class="cp-stats">
                            <div class="cp-stat">
                                <span class="value">{{ $row->total12mFormatted }}</span>
                                <span class="label">{{ Lang::get('counterparties::index.stat_12mo') }}</span>
                            </div>
                        </div>
                        @if ($row->isPartial())
                            <div style="font-size: var(--text-xs); color: var(--color-text-faint);" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $row->unconvertedList()]) }}</div>
                        @endif
                        @if ($row->recentLine !== null)
                            <div class="cp-recent"><span>{{ $row->recentLine }}</span></div>
                        @endif
                        <div style="font-size: var(--text-xs); color: var(--color-amber); margin-top: auto;">
                            ❋ {{ Lang::get('counterparties::index.label_this') }}
                        </div>
                    </a>
                @else
                    <a
                        href="{{ $row->href }}"
                        class="cp-card focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                    >
                        <header class="cp-head">
                            <span class="cp-head-name">{{ $row->displayName }}</span>
                            <x-counterparties::type-chip :type="$row->type" />
                        </header>
                        {{-- Each amount and its label are one item, so a card too
                             narrow for both pairs breaks BETWEEN them. Flat, the
                             row wrapped wherever it ran out: two amounts and one
                             label on the first line, the other label under them,
                             with nothing saying which amount it belonged to. --}}
                        <div class="cp-stats">
                            <div class="cp-stat">
                                <span class="value">{{ $row->total12mFormatted }}</span>
                                <span class="label">
                                    @if ($row->type === CounterpartyType::Personal->value){{ Lang::get('counterparties::index.stat_net_received') }}@else{{ Lang::get('counterparties::index.stat_12mo') }}@endif
                                </span>
                            </div>
                            <div class="cp-stat">
                                <span class="value" style="font-size: var(--text-sm);">{{ $row->avgPerMonthFormatted }}</span>
                                <span class="label">{{ Lang::get('counterparties::index.stat_avg_mo') }}</span>
                            </div>
                        </div>
                        @if ($row->isPartial())
                            <div style="font-size: var(--text-xs); color: var(--color-text-faint);" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $row->unconvertedList()]) }}</div>
                        @endif
                        <div role="img" class="cp-spark" aria-label="{{ Lang::get('counterparties::index.sparkline_aria') }}">
                            @php
                                $sparkMax = max(1, max(array_map('abs', $row->sparkline)));
                                $sparkLastIdx = count($row->sparkline) - 1;
                            @endphp
                            @foreach ($row->sparkline as $idx => $bar)
                                @php
                                    $heightPct = (int) round((abs($bar) / $sparkMax) * 100);
                                @endphp
                                <span
                                    class="bar {{ $idx === $sparkLastIdx ? 'last' : '' }}"
                                    style="height: {{ $heightPct }}%;"
                                    aria-hidden="true"
                                ></span>
                            @endforeach
                        </div>
                        @if ($row->recentLine !== null)
                            <div class="cp-recent"><span>{{ $row->recentLine }}</span></div>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    @else
        {{-- List view: desktop table + phone card-list-item degradation --}}
        @foreach ($rows as $row)
            {{-- phone-only: .card-list-item renders each row as a tidy two-line card --}}
            <a
                href="{{ $row->href }}"
                class="card-list-item phone-only focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                style="text-decoration: none; display: flex;"
            >
                <div style="flex: 1 1 auto; min-width: 0;">
                    <span class="primary">{{ $row->displayName }}</span>
                    <span class="secondary">
                        <x-counterparties::type-chip :type="$row->type" />
                    </span>
                </div>
                <div style="flex: 0 0 auto; text-align: right;">
                    <span class="amount" style="{{ $row->total12mMinor > 0 ? 'color: var(--color-emerald)' : '' }}">{{ $row->total12mFormatted }}</span>
                    @if ($row->isPartial())
                        <span class="secondary" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $row->unconvertedList()]) }}</span>
                    @endif
                </div>
            </a>
        @endforeach

        {{-- desktop-only: standard table --}}
        <div class="frame desktop-only" style="padding: 0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background: var(--color-surface-2); text-align: left;">
                    <tr style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted);">
                        <th style="padding: var(--space-2) var(--space-3);">{{ Lang::get('counterparties::index.table_name') }}</th>
                        <th style="padding: var(--space-2) var(--space-3);">{{ Lang::get('counterparties::index.table_type') }}</th>
                        <th style="padding: var(--space-2) var(--space-3); text-align: right;">{{ Lang::get('counterparties::index.table_12mo') }}</th>
                        <th style="padding: var(--space-2) var(--space-3); text-align: right;">{{ Lang::get('counterparties::index.table_avg') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="border-top: 1px solid var(--color-border);">
                            <td style="padding: var(--space-2) var(--space-3);">
                                <a href="{{ $row->href }}" style="color: var(--color-text); text-decoration: none;">
                                    {{ $row->displayName }}
                                </a>
                            </td>
                            <td style="padding: var(--space-2) var(--space-3);">
                                <x-counterparties::type-chip :type="$row->type" />
                            </td>
                            <td style="padding: var(--space-2) var(--space-3); text-align: right; font-variant-numeric: tabular-nums;">
                                {{ $row->total12mFormatted }}
                            </td>
                            <td style="padding: var(--space-2) var(--space-3); text-align: right; font-variant-numeric: tabular-nums;">
                                {{ $row->avgPerMonthFormatted }}
                                @if ($row->isPartial())
                                    <span style="display: block; font-size: var(--text-xs); color: var(--color-text-faint);" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $row->unconvertedList()]) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
