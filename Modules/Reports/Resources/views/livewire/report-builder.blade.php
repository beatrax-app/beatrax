@use('Modules\Core\Public\Support\Lang')
{{--
    The `/reports` live single-page builder — control
    rail (left) + result panel (right, chart above an always-on table).
    Every control writes straight to a `#[Url]`-bound property via
    wire:click="$set(...)"/wire:model.live; changing anything re-renders
    live, no reload, no Apply button.

    Variables in scope (Modules\Reports\Internal\Http\Livewire\ReportBuilder::render()):
      $result                 ReportResultDto
      $displayRows             list<ReportResultRow>  — comparisonRows when compare is on, else $result->rows
      $definition               ReportDefinition
      $drilldownUrls            list<string>            — parallel to $displayRows
      $showDimension            bool                     — hidden entirely when metric=net_worth
      $showGranularity          bool                     — shown only for time-series reports
      $availableAccounts / $availableCategories / $availableCounterparties  list<array{id:int,name:string,...}>

    Public component properties (metric, dimension, periodPreset, customFrom,
    customTo, granularity, currencyMode, viz, compare, filterAccounts,
    filterCategories, filterCounterparties, filterAmountMin, filterAmountMax,
    filterAmountDir, showSaveForm, saveName, flashMessage) are exposed
    directly to the view by Livewire.
--}}
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->format();

    $amountClass = static fn (int $minor): string => $minor < 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-slate-900 dark:text-slate-100';

    $metricLabels = [
        'spend' => Lang::get('reports::builder.metric.spend'),
        'income' => Lang::get('reports::builder.metric.income'),
        'net' => Lang::get('reports::builder.metric.net'),
        'net_worth' => Lang::get('reports::builder.metric.net_worth'),
    ];
    $dimensionLabels = [
        'category' => Lang::get('reports::builder.dimension.category'),
        'time_bucket' => Lang::get('reports::builder.dimension.time_bucket'),
        'counterparty' => Lang::get('reports::builder.dimension.counterparty'),
        'account' => Lang::get('reports::builder.dimension.account'),
    ];
    $periodLabels = [
        'this_month' => Lang::get('reports::builder.period.this_month'),
        'last_3_months' => Lang::get('reports::builder.period.last_3_months'),
        'last_6_months' => Lang::get('reports::builder.period.last_6_months'),
        'last_12_months' => Lang::get('reports::builder.period.last_12_months'),
        'ytd' => Lang::get('reports::builder.period.ytd'),
        'this_year' => Lang::get('reports::builder.period.this_year'),
        'custom' => Lang::get('reports::builder.period.custom'),
    ];
    $vizLabels = [
        'table' => Lang::get('reports::builder.viz.table'),
        'bar' => Lang::get('reports::builder.viz.bar'),
        'line' => Lang::get('reports::builder.viz.line'),
        'donut' => Lang::get('reports::builder.viz.donut'),
    ];

    $groupHeader = match ($definition->dimension) {
        'category' => Lang::get('reports::builder.group_header.category'),
        'counterparty' => Lang::get('reports::builder.group_header.counterparty'),
        'account' => Lang::get('reports::builder.group_header.account'),
        'time_bucket' => Lang::get('reports::builder.group_header.month'),
        default => Lang::get('reports::builder.group_header.default'),
    };

    $metricLabel = $metricLabels[$definition->metric] ?? Lang::get('reports::builder.metric.fallback');

    $hasResults = $displayRows !== [];

    $exportParams = array_filter([
        'metric' => $metric,
        'dim' => $dimension,
        'period' => $periodPreset,
        'from' => $customFrom !== '' ? $customFrom : null,
        'to' => $customTo !== '' ? $customTo : null,
        'gran' => $granularity,
        'ccy' => $currencyMode,
        'viz' => $viz,
        'cmp' => $compare ? '1' : '0',
        'account' => $filterAccounts,
        'category' => $filterCategories,
        'counterparty' => $filterCounterparties,
        'amount_min' => $filterAmountMin !== '' ? $filterAmountMin : null,
        'amount_max' => $filterAmountMax !== '' ? $filterAmountMax : null,
        'amount_dir' => $filterAmountDir,
    ], static fn (mixed $v): bool => $v !== null && $v !== '');
    $exportUrl = route('reports.export', $exportParams);

    // Headline delta — current total minus the previous-period
    // total, derived from $displayRows' previousAmountMinor (only
    // populated when compare is on and $displayRows is $result->comparisonRows).
    $previousTotal = 0;
    if ($definition->compare) {
        foreach ($displayRows as $dRow) {
            $previousTotal += $dRow->previousAmountMinor ?? 0;
        }
    }
    $headlineDelta = $result->totalMinor - $previousTotal;
@endphp

<div class="space-y-8" style="padding: var(--space-6) var(--space-4); max-width: 1280px; margin: 0 auto;">
    <header class="space-y-2">
        <h1 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">{{ Lang::get('reports::builder.title') }}</h1>
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">{{ Lang::get('reports::builder.subtitle') }}</p>
    </header>

    @if ($flashMessage !== '')
        <div
            aria-atomic="true"
            aria-live="polite"
            class="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
            style="border-color: var(--color-emerald); background: var(--color-emerald-bg); color: var(--color-emerald);"
        >
            <span>{{ $flashMessage }}</span>
            <x-core::emoji-action
                :label="Lang::get('reports::builder.dismiss')"
                wire:click="clearFlash"
            >✖️</x-core::emoji-action>
        </div>
    @endif

    <div style="display: flex; gap: var(--space-8); align-items: flex-start; flex-wrap: wrap;">

        {{-- ─── Control rail ──────────────────────────────────────────── --}}
        <aside
            class="rpt-rail"
            aria-label="{{ Lang::get('reports::builder.controls_aria') }}"
        >
            {{-- Metric --}}
            <div>
                <p class="srch-filter-label" style="margin-bottom: var(--space-2);">{{ Lang::get('reports::builder.metric.heading') }}</p>
                <div role="group" aria-label="{{ Lang::get('reports::builder.metric.heading') }}" class="filter-chips">
                    @foreach ($metricLabels as $key => $label)
                        <button
                            type="button"
                            wire:click="$set('metric', '{{ $key }}')"
                            class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                            aria-pressed="{{ $metric === $key ? 'true' : 'false' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Group-by — hidden entirely when metric = net_worth --}}
            @if ($showDimension)
                <div>
                    <p class="srch-filter-label" style="margin-bottom: var(--space-2);">{{ Lang::get('reports::builder.group_by') }}</p>
                    <div role="group" aria-label="{{ Lang::get('reports::builder.group_by') }}" class="filter-chips">
                        @foreach ($dimensionLabels as $key => $label)
                            <button
                                type="button"
                                wire:click="$set('dimension', '{{ $key }}')"
                                class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                                aria-pressed="{{ $dimension === $key ? 'true' : 'false' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Period --}}
            <div>
                <p class="srch-filter-label" style="margin-bottom: var(--space-2);">{{ Lang::get('reports::builder.period.heading') }}</p>
                <div role="group" aria-label="{{ Lang::get('reports::builder.period.heading') }}" class="filter-chips">
                    @foreach ($periodLabels as $key => $label)
                        <button
                            type="button"
                            wire:click="$set('periodPreset', '{{ $key }}')"
                            class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                            aria-pressed="{{ $periodPreset === $key ? 'true' : 'false' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
                @if ($periodPreset === 'custom')
                    <div class="srch-date-range mt-2">
                        <label for="report-custom-from" class="srch-filter-label">{{ Lang::get('reports::builder.period.from') }}</label>
                        <x-core::date-input field-id="report-custom-from" wire:model.live="customFrom" />
                        <label for="report-custom-to" class="srch-filter-label mt-1">{{ Lang::get('reports::builder.period.to') }}</label>
                        <x-core::date-input field-id="report-custom-to" wire:model.live="customTo" />
                    </div>
                @endif
            </div>

            {{-- Currency mode --}}
            <div>
                <p class="srch-filter-label" style="margin-bottom: var(--space-2);">{{ Lang::get('reports::builder.currency.heading') }}</p>
                <div class="view-toggle" role="group" aria-label="{{ Lang::get('reports::builder.currency.aria') }}">
                    <button type="button" wire:click="$set('currencyMode', 'base')" class="{{ $currencyMode === 'base' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" aria-pressed="{{ $currencyMode === 'base' ? 'true' : 'false' }}">{{ Lang::get('reports::builder.currency.base') }}</button>
                    <button type="button" wire:click="$set('currencyMode', 'original')" class="{{ $currencyMode === 'original' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" aria-pressed="{{ $currencyMode === 'original' ? 'true' : 'false' }}">{{ Lang::get('reports::builder.currency.original') }}</button>
                </div>
            </div>

            {{-- Time-granularity — only for time-series reports --}}
            @if ($showGranularity)
                <div>
                    <p class="srch-filter-label" style="margin-bottom: var(--space-2);">{{ Lang::get('reports::builder.granularity.heading') }}</p>
                    <div class="view-toggle" role="group" aria-label="{{ Lang::get('reports::builder.granularity.aria') }}">
                        <button type="button" wire:click="$set('granularity', 'monthly')" class="{{ $granularity === 'monthly' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" aria-pressed="{{ $granularity === 'monthly' ? 'true' : 'false' }}">{{ Lang::get('reports::builder.granularity.monthly') }}</button>
                        <button type="button" wire:click="$set('granularity', 'weekly')" class="{{ $granularity === 'weekly' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" aria-pressed="{{ $granularity === 'weekly' ? 'true' : 'false' }}">{{ Lang::get('reports::builder.granularity.weekly') }}</button>
                    </div>
                </div>
            @endif

            {{-- Filters — reused Search filter language --}}
            <div>
                <p class="srch-filter-label" style="margin-bottom: var(--space-2);">{{ Lang::get('reports::builder.filters.heading') }}</p>
                <div class="srch-chips" style="flex-wrap: wrap;">
                    @include('reports::livewire.partials.report-filter-popovers')
                </div>
            </div>

            {{-- Compare to previous period --}}
            <div class="flex items-center justify-between gap-2">
                <label for="report-compare-switch" class="srch-filter-label" style="margin: 0;">{{ Lang::get('reports::builder.compare') }}</label>
                <x-core::switch
                    id="report-compare-switch"
                    :on="$compare"
                    :label="Lang::get('reports::builder.compare')"
                    wire:click="$set('compare', {{ $compare ? 'false' : 'true' }})"
                />
            </div>

            {{-- Visualization --}}
            <div>
                <p class="srch-filter-label" style="margin-bottom: var(--space-2);">{{ Lang::get('reports::builder.viz.heading') }}</p>
                <div role="group" aria-label="{{ Lang::get('reports::builder.viz.heading') }}" class="filter-chips">
                    @foreach ($vizLabels as $key => $label)
                        <button
                            type="button"
                            wire:click="$set('viz', '{{ $key }}')"
                            class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                            aria-pressed="{{ $viz === $key ? 'true' : 'false' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </aside>

        {{-- ─── Result panel ──────────────────────────────────────────── --}}
        <section style="flex: 1 1 480px; min-width: 0; display: flex; flex-direction: column; gap: var(--space-4);" aria-label="{{ Lang::get('reports::builder.result_aria') }}">

            {{-- Actions row --}}
            <div class="flex items-center gap-2 flex-wrap">
                @if (! $showSaveForm)
                    {{-- Button copy distinguishes "editing a loaded report" from "saving a fresh one" so the user understands which action they're about to take. --}}
                    <button type="button" wire:click="openSaveForm" @disabled(! $hasResults) class="pill-btn-primary">{{ $loadedReportId !== null ? Lang::get('reports::builder.actions.update_report') : Lang::get('reports::builder.actions.save_report') }}</button>
                @else
                    <form wire:submit.prevent="save" class="flex items-center gap-2">
                        <input type="text" wire:model="saveName" placeholder="{{ Lang::get('reports::builder.actions.report_name') }}" class="srch-amount-input" style="min-width: 200px;" aria-label="{{ Lang::get('reports::builder.actions.report_name') }}" autofocus />
                        <button type="submit" class="pill-btn-primary">{{ $loadedReportId !== null ? Lang::get('reports::builder.actions.update') : Lang::get('reports::builder.actions.save') }}</button>
                        <button type="button" wire:click="cancelSaveForm" class="pill-btn-ghost">{{ Lang::get('reports::builder.actions.cancel') }}</button>
                    </form>
                @endif

                {{-- Export CSV is a real Livewire action (ReportBuilder::export()) so it can participate in wire:loading — mirrors Tax page's ↓ → … swap verbatim. --}}
                @if ($hasResults)
                    <button
                        type="button"
                        wire:click="export"
                        wire:loading.attr="aria-busy"
                        wire:loading.attr="disabled"
                        wire:target="export"
                        class="pill-btn-ghost"
                        aria-label="{{ Lang::get('reports::builder.actions.export_csv') }}"
                    >
                        <span aria-hidden="true" wire:loading.remove wire:target="export">↓</span>
                        <span aria-hidden="true" wire:loading wire:target="export">…</span>
                        {{ Lang::get('reports::builder.actions.export_csv') }}
                    </button>
                @else
                    <span class="pill-btn-ghost" style="opacity: .5; cursor: not-allowed;" aria-disabled="true">↓ {{ Lang::get('reports::builder.actions.export_csv') }}</span>
                @endif

                {{-- Report-recompute loading feedback — the existing inline "…" glyph pattern, keyed to every mutable control-rail property so any rail interaction (metric/dimension/period/currency/granularity/filters/compare/viz) shows it. --}}
                <span
                    wire:loading
                    wire:target="metric,dimension,periodPreset,customFrom,customTo,granularity,currencyMode,viz,compare,filterAccounts,filterCategories,filterCounterparties,filterAmountMin,filterAmountMax,filterAmountDir"
                    style="font-size: var(--text-xs); color: var(--color-text-muted);"
                    aria-atomic="true"
                    aria-live="polite"
                >{{ Lang::get('reports::builder.updating') }}</span>
            </div>

            @if (! $hasResults)
                {{-- Friendly empty state (Req: never an error) — rail stays interactive --}}
                <div class="srch-no-results" aria-live="polite" aria-atomic="true">
                    <p class="srch-no-results__heading">{{ Lang::get('reports::builder.empty.heading') }}</p>
                    <p class="srch-no-results__body">{{ Lang::get('reports::builder.empty.body') }}</p>
                </div>
            @else
                @php
                    // The DOM id must stay stable for as long as the SAME
                    // ApexCharts partial (viz type) is mounted — everything else
                    // (metric/dimension/period/granularity/currency/filters/compare/
                    // row count) is a content-only change that the `report-updated`
                    // Alpine listener + `chart.updateOptions()` now handles in
                    // place. Hashing off `viz` alone (rather than the previous
                    // [metric, dimension, periodPreset, viz, count($displayRows)]
                    // tuple) is what makes that possible: only a real `viz`
                    // switch — which renders an entirely different @include
                    // partial with its own fresh x-data wrapper anyway — changes
                    // the id, so morphdom never tears down and recreates the
                    // chart div for a same-viz control change.
                    $chartElementId = 'report-chart-'.$definition->viz;
                @endphp

                @if ($viz === 'bar')
                    @include('reports::livewire.partials.report-bar-chart', ['chartElementId' => $chartElementId, 'rows' => $displayRows, 'drilldownUrls' => $drilldownUrls, 'metricLabel' => $metricLabel])
                @elseif ($viz === 'line')
                    @include('reports::livewire.partials.report-line-chart', ['chartElementId' => $chartElementId, 'rows' => $displayRows, 'drilldownUrls' => $drilldownUrls, 'metricLabel' => $metricLabel])
                @elseif ($viz === 'donut')
                    @include('reports::livewire.partials.report-donut-chart', ['chartElementId' => $chartElementId, 'rows' => $displayRows, 'drilldownUrls' => $drilldownUrls, 'metricLabel' => $metricLabel])
                @endif

                {{-- Total + FX exclusion note + headline delta --}}
                <div class="space-y-1">
                    <div class="flex items-baseline justify-between gap-4">
                        <span class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('reports::builder.total_prefix') }} {{ strtolower($metricLabel) }}</span>
                        <span class="{{ $amountClass($result->totalMinor) }}" style="font-size: var(--text-3xl); font-weight: 600; font-variant-numeric: tabular-nums;">
                            {{ $fmt($result->totalMinor, $result->currency) }}
                        </span>
                    </div>
                    @if ($definition->compare)
                        <div class="flex items-center justify-end gap-2">
                            <span class="text-xs" style="color: var(--color-text-muted);">{{ Lang::get('reports::builder.vs_previous') }}</span>
                            <span
                                style="font-variant-numeric: tabular-nums; font-weight: 600;"
                                class="{{ $headlineDelta >= 0 ? 'text-emerald-600 dark:text-emerald-500' : 'text-rose-600 dark:text-rose-400' }}"
                            >{{ $headlineDelta >= 0 ? '+' : '−' }}{{ $fmt(abs($headlineDelta), $result->currency) }}</span>
                        </div>
                    @endif
                    {{-- Fees and adjustments are in no metric, by design: a bank
                         fee is not category spending. Shown beside the total
                         rather than folded into it, so a total that omits money
                         does not read as everything that left the account. --}}
                    @if ($result->otherMovementMinor !== 0)
                        <div class="flex items-baseline justify-between gap-4">
                            <span class="text-xs" style="color: var(--color-text-muted);">{{ Lang::get('reports::builder.other_movement') }}</span>
                            <span class="text-xs" style="color: var(--color-text-muted); font-variant-numeric: tabular-nums;">
                                {{ $fmt($result->otherMovementMinor, $result->currency) }}
                            </span>
                        </div>
                    @endif
                    @if ($result->hasExcludedAccounts)
                        <p class="text-xs" style="color: var(--color-amber);">
                            {{ Lang::choice('reports::builder.fx_excluded', $result->accountsWithoutRate, ['count' => $result->accountsWithoutRate]) }}
                        </p>
                    @endif
                </div>

                {{-- Always-on data table — same $displayRows as the chosen chart --}}
                <x-core::data-table>
                    <x-slot:head>
                        <x-core::th align="left">{{ $groupHeader }}</x-core::th>
                        <x-core::th align="right">{{ $metricLabel }}</x-core::th>
                        @if ($definition->compare)
                            <x-core::th align="right">{{ Lang::get('reports::builder.vs_previous') }}</x-core::th>
                        @endif
                    </x-slot:head>

                    @foreach ($displayRows as $rowIndex => $row)
                        <tr wire:key="report-row-{{ $row->groupKey ?? 'null' }}-{{ $rowIndex }}">
                            <td class="px-4 py-2 text-slate-900 dark:text-slate-100">
                                <a
                                    href="{{ $drilldownUrls[$rowIndex] ?? '#' }}"
                                    class="hover:underline"
                                    title="{{ Lang::get('reports::builder.view_transactions') }}"
                                >{{ $row->groupLabel }}</a>
                            </td>
                            <td class="px-4 py-2 text-right {{ $amountClass($row->amountMinor) }}" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($row->amountMinor, $row->currency) }}
                            </td>
                            @if ($definition->compare)
                                <td class="px-4 py-2 text-right" style="font-variant-numeric: tabular-nums;">
                                    @if ($row->deltaMinor !== null)
                                        <span class="{{ $row->deltaMinor >= 0 ? 'text-emerald-600 dark:text-emerald-500' : 'text-rose-600 dark:text-rose-400' }}">
                                            {{ $row->deltaMinor >= 0 ? '+' : '−' }}{{ $fmt(abs($row->deltaMinor), $row->currency) }}
                                        </span>
                                    @else
                                        <span class="text-slate-400 dark:text-slate-500">—</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach

                    <x-slot:foot>
                        <td class="px-4 py-2 font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('reports::builder.total') }}</td>
                        <td class="px-4 py-2 text-right font-semibold {{ $amountClass($result->totalMinor) }}" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt($result->totalMinor, $result->currency) }}
                        </td>
                        @if ($definition->compare)
                            <td class="px-4 py-2 text-right font-semibold" style="font-variant-numeric: tabular-nums;">
                                <span class="{{ $headlineDelta >= 0 ? 'text-emerald-600 dark:text-emerald-500' : 'text-rose-600 dark:text-rose-400' }}">
                                    {{ $headlineDelta >= 0 ? '+' : '−' }}{{ $fmt(abs($headlineDelta), $result->currency) }}
                                </span>
                            </td>
                        @endif
                    </x-slot:foot>
                </x-core::data-table>
            @endif
        </section>
    </div>
</div>
