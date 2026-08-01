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
    use Illuminate\Support\Number;
    $totalEntities = $counts['all'] ?? 0;
    $unknownCount = $counts['unknown'] ?? 0;
@endphp

<div class="space-y-8" style="padding: var(--space-6) var(--space-4); max-width: 1200px; margin: 0 auto;">
    {{-- Page head ------------------------------------------------- --}}
    <header class="space-y-2">
        <h1 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
            Counterparties
        </h1>
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
            @if ($unknownCount > 0)
                {{ $totalEntities }} entities ·
                <a
                    href="{{ route('counterparties.triage') }}"
                    style="color: var(--color-amber); text-decoration: underline;"
                >{{ $unknownCount }} need identification</a>
            @else
                {{ $totalEntities }} entities
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
                placeholder="Search by name, alias, or IBAN…"
                aria-label="Search counterparties"
                disabled
            />
            <span class="kbd" aria-hidden="true">/</span>
        </div>

        <span style="font-size: var(--text-sm); color: var(--color-text-muted);">
            Sort: Total 12mo ↓
        </span>

        <div class="view-toggle" role="group" aria-label="View mode">
            <button
                type="button"
                class="{{ $activeView === 'cards' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                aria-pressed="{{ $activeView === 'cards' ? 'true' : 'false' }}"
                wire:click="setView('cards')"
            >▦ Cards</button>
            <button
                type="button"
                class="{{ $activeView === 'list' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                aria-pressed="{{ $activeView === 'list' ? 'true' : 'false' }}"
                wire:click="setView('list')"
            >≡ List</button>
        </div>
        </div>
    </div>

    {{-- Type-filter chip row -------------------------------------- --}}
    <div role="group" aria-label="Filter by type" class="filter-chips">
        @php
            $chipDefs = [
                ['key' => 'all', 'label' => 'All', 'dot' => null],
                ['key' => 'merchant', 'label' => 'Merchants', 'dot' => 'dot-merchant'],
                ['key' => 'personal', 'label' => 'Personal', 'dot' => 'dot-personal'],
                ['key' => 'bank', 'label' => 'Banks', 'dot' => 'dot-bank'],
                ['key' => 'government', 'label' => 'Government', 'dot' => 'dot-gov'],
                ['key' => 'self', 'label' => 'Self', 'dot' => 'dot-self'],
                ['key' => 'unknown', 'label' => 'Unknown', 'dot' => 'dot-unknown'],
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
        <section class="frame" style="text-align: center; padding: var(--space-10);">
            <h2 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-3);">
                No counterparties yet
            </h2>
            <p style="font-size: var(--text-base); color: var(--color-text-muted); margin: 0 0 var(--space-4); max-width: 480px; margin-left: auto; margin-right: auto;">
                Counterparties appear here automatically as you import transactions. Import a statement to get started.
            </p>
            <a
                href="/imports"
                class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                style="display: inline-block;"
            >Import a statement →</a>
        </section>
    @elseif ($activeView === 'cards')
        {{-- cards grid: single-column at phone width, auto-fill at >=768px --}}
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--space-5);"
             class="cp-cards-grid">
            @foreach ($rows as $row)
                @php
                    $totalFormatted = Number::currency(abs($row->total12mMinor) / Money::MINOR_UNITS_PER_MAJOR, 'EUR', 'nl');
                    $avgFormatted = Number::currency(abs($row->avgPerMonthMinor) / Money::MINOR_UNITS_PER_MAJOR, 'EUR', 'nl');
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
                            <span>Routing only</span>
                            <span>no spend / no income</span>
                        </div>
                        <div style="font-size: var(--text-xs); color: var(--color-text-muted); margin-top: auto;">
                            Open account →
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
                            <span class="label">12 mo</span>
                        </div>
                        @if ($row->recentLine !== null)
                            <div class="cp-recent"><span>{{ $row->recentLine }}</span></div>
                        @endif
                        <div style="font-size: var(--text-xs); color: var(--color-amber); margin-top: auto;">
                            ❋ Label this counterparty
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
                                @if ($row->type === 'personal') Net received @else 12 mo @endif
                            </span>
                            <span class="value" style="font-size: var(--text-sm);">{{ $avgFormatted }}</span>
                            <span class="label">Avg / mo</span>
                        </div>
                        <div class="cp-spark" aria-label="12-month activity sparkline">
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
                $totalFormatted = Number::currency(abs($row->total12mMinor) / Money::MINOR_UNITS_PER_MAJOR, 'EUR', 'nl');
                $avgFormatted = Number::currency(abs($row->avgPerMonthMinor) / Money::MINOR_UNITS_PER_MAJOR, 'EUR', 'nl');
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
                        <th style="padding: var(--space-2) var(--space-3);">Name</th>
                        <th style="padding: var(--space-2) var(--space-3);">Type</th>
                        <th style="padding: var(--space-2) var(--space-3); text-align: right;">12 mo</th>
                        <th style="padding: var(--space-2) var(--space-3); text-align: right;">Avg / mo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $totalFormatted = Number::currency(abs($row->total12mMinor) / Money::MINOR_UNITS_PER_MAJOR, 'EUR', 'nl');
                            $avgFormatted = Number::currency(abs($row->avgPerMonthMinor) / Money::MINOR_UNITS_PER_MAJOR, 'EUR', 'nl');
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
