@use('Modules\Core\Public\Support\Lang')
{{--
    /counterparties index — cards-default grid with type-filter chip
    row and the Cards / List view toggle persisted in
    user_preferences.counterparty_index_view.

    Consumes the 17-06a x-components:
      - <x-counterparties::filter-chips>  — type filter row
      - <x-counterparties::cp-card>       — grid card
      - <x-counterparties::type-chip>     — per-row type label

    All copy is verbatim from 17-UI-SPEC.md (Counterparty index table).

    Variables exposed by `CounterpartyIndex::render()`:
      $rows       Illuminate\Support\Collection<CounterpartyIndexRow>
      $counts     array<string, int>  — per-type chip counts
      $activeType string                — currently selected filter chip
      $activeView string                — 'cards' | 'list'
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    $totalEntities = $counts['all'] ?? 0;
    $unknownCount = $counts['unknown'] ?? 0;
@endphp

<div class="space-y-8" style="padding: var(--space-6) var(--space-4); max-width: 1200px; margin: 0 auto;">
    {{-- Page head ------------------------------------------------- --}}
    <header class="space-y-2">
        <h1 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
            {{ Lang::get('counterparties::index.heading') }}
        </h1>
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
            @if ($unknownCount > 0)
                {{ Lang::get('counterparties::index.entities', ['count' => $totalEntities]) }} ·
                <a
                    href="{{ route('counterparties.triage') }}"
                    style="color: var(--color-amber); text-decoration: underline;"
                >{{ Lang::get('counterparties::index.need_identification', ['count' => $unknownCount]) }}</a>
            @else
                {{ Lang::get('counterparties::index.entities', ['count' => $totalEntities]) }}
            @endif
        </p>
    </header>

    {{-- Toolbar: phone collapse (D-07) vs. full desktop toolbar --------- --}}
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
            <span class="ic" aria-hidden="true">⌕</span>
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
                ['key' => 'all', 'label' => Lang::get('counterparties::index.chips.all'), 'dot' => null],
                ['key' => 'merchant', 'label' => Lang::get('counterparties::index.chips.merchant'), 'dot' => 'dot-merchant'],
                ['key' => 'personal', 'label' => Lang::get('counterparties::index.chips.personal'), 'dot' => 'dot-personal'],
                ['key' => 'bank', 'label' => Lang::get('counterparties::index.chips.bank'), 'dot' => 'dot-bank'],
                ['key' => 'government', 'label' => Lang::get('counterparties::index.chips.government'), 'dot' => 'dot-gov'],
                ['key' => 'self', 'label' => Lang::get('counterparties::index.chips.self'), 'dot' => 'dot-self'],
                ['key' => 'unknown', 'label' => Lang::get('counterparties::index.chips.unknown'), 'dot' => 'dot-unknown'],
            ];
        @endphp
        @foreach ($chipDefs as $chip)
            <button
                type="button"
                class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                aria-pressed="{{ $activeType === $chip['key'] ? 'true' : 'false' }}"
                wire:click="setType('{{ $chip['key'] }}')"
            >
                @if ($chip['dot'] !== null)
                    <span class="chip-dot {{ $chip['dot'] }}" aria-hidden="true"></span>
                @endif
                <span>{{ $chip['label'] }}</span>
                <span class="chip-count">{{ $counts[$chip['key']] ?? 0 }}</span>
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
                href="/imports"
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
                    $totalFormatted = Money::ofMinor(abs($row->total12mMinor), 'EUR')->format();
                    $avgFormatted = Money::ofMinor(abs($row->avgPerMonthMinor), 'EUR')->format();
                    $isUnknown = $row->type === 'unknown';
                    $isSelf = $row->type === 'self_account';
                @endphp
                @if ($isSelf)
                    <a
                        href="/accounts/{{ $row->slug }}"
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
                        href="{{ route('counterparties.triage', ['queue_first' => $row->id]) }}"
                        class="cp-card unknown focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                    >
                        <header class="cp-head">
                            <span class="cp-head-name">{{ $row->displayName }}</span>
                            <x-counterparties::type-chip :type="$row->type" />
                        </header>
                        <div class="cp-stats">
                            <span class="value">{{ $totalFormatted }}</span>
                            <span class="label">{{ Lang::get('counterparties::index.stat_12mo') }}</span>
                        </div>
                        @if ($row->recentLine !== null)
                            <div class="cp-recent"><span>{{ $row->recentLine }}</span></div>
                        @endif
                        <div style="font-size: var(--text-xs); color: var(--color-amber); margin-top: auto;">
                            ❋ {{ Lang::get('counterparties::index.label_this') }}
                        </div>
                    </a>
                @else
                    <a
                        href="{{ route('counterparties.profile', ['slug' => $row->slug]) }}"
                        class="cp-card focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                    >
                        <header class="cp-head">
                            <span class="cp-head-name">{{ $row->displayName }}</span>
                            <x-counterparties::type-chip :type="$row->type" />
                        </header>
                        <div class="cp-stats">
                            <span class="value">{{ $totalFormatted }}</span>
                            <span class="label">
                                @if ($row->type === 'personal'){{ Lang::get('counterparties::index.stat_net_received') }}@else{{ Lang::get('counterparties::index.stat_12mo') }}@endif
                            </span>
                            <span class="value" style="font-size: var(--text-sm);">{{ $avgFormatted }}</span>
                            <span class="label">{{ Lang::get('counterparties::index.stat_avg_mo') }}</span>
                        </div>
                        <div role="img" class="cp-spark" aria-label="{{ Lang::get('counterparties::index.sparkline_aria') }}">
                            @foreach ($row->sparkline as $idx => $bar)
                                @php
                                    $isLast = $idx === count($row->sparkline) - 1;
                                    $max = max(1, max(array_map('abs', $row->sparkline)));
                                    $heightPct = (int) round((abs($bar) / $max) * 100);
                                @endphp
                                <span
                                    class="bar {{ $isLast ? 'last' : '' }}"
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
            @php
                $totalFormatted = Money::ofMinor(abs($row->total12mMinor), 'EUR')->format();
                $avgFormatted = Money::ofMinor(abs($row->avgPerMonthMinor), 'EUR')->format();
                $href = match (true) {
                    $row->type === 'self_account' => '/accounts/'.$row->slug,
                    $row->type === 'unknown' => route('counterparties.triage', ['queue_first' => $row->id]),
                    default => route('counterparties.profile', ['slug' => $row->slug]),
                };
            @endphp
            {{-- phone-only: .card-list-item renders each row as a tidy two-line card --}}
            <a
                href="{{ $href }}"
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
                    <span class="amount" style="{{ $row->total12mMinor > 0 ? 'color: var(--color-emerald)' : '' }}">{{ $totalFormatted }}</span>
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
                        @php
                            $totalFormatted = Money::ofMinor(abs($row->total12mMinor), 'EUR')->format();
                            $avgFormatted = Money::ofMinor(abs($row->avgPerMonthMinor), 'EUR')->format();
                            $href = match (true) {
                                $row->type === 'self_account' => '/accounts/'.$row->slug,
                                $row->type === 'unknown' => route('counterparties.triage', ['queue_first' => $row->id]),
                                default => route('counterparties.profile', ['slug' => $row->slug]),
                            };
                        @endphp
                        <tr style="border-top: 1px solid var(--color-border);">
                            <td style="padding: var(--space-2) var(--space-3);">
                                <a href="{{ $href }}" style="color: var(--color-text); text-decoration: none;">
                                    {{ $row->displayName }}
                                </a>
                            </td>
                            <td style="padding: var(--space-2) var(--space-3);">
                                <x-counterparties::type-chip :type="$row->type" />
                            </td>
                            <td style="padding: var(--space-2) var(--space-3); text-align: right; font-variant-numeric: tabular-nums;">
                                {{ $totalFormatted }}
                            </td>
                            <td style="padding: var(--space-2) var(--space-3); text-align: right; font-variant-numeric: tabular-nums;">
                                {{ $avgFormatted }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
