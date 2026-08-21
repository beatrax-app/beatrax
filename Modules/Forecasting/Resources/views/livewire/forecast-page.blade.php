@use('Modules\Core\Public\Support\Lang')
{{--
    /forecast page — Wave 4 surface.

    Page contract:
      - Heading + subheading + "Adjust buffers" deep link.
      - Per-account tab bar (Wave 2).
      - 30 / 60 / 90 horizon segmented control (Wave 2).
      - View by funder toggle (Wave 4 wires the URL state; Wave 5
        implements the collapse semantics).
      - Scenario picker — baseline radio + one radio per saved
        scenario + "+ New scenario" chip with inline create form.
      - When scenarioId !== null:
          * Net diff tile above the panel pair (direction-aware
            emerald-700 / rose-700 / slate-900 tints).
          * Two-panel side-by-side rangeArea grid (baseline LEFT,
            scenario RIGHT) sharing y-axis.
          * Scenario sidebar in the right rail with Rename / Delete
            scenario / mutation list + Add chooser.
      - When scenarioId === null: baseline-only single panel (Wave 2/3
        behaviour preserved).
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    use Modules\Forecasting\Public\Dto\ForecastDto;

    $eurFmt = static fn (int $minor, string $currency = 'EUR'): string => Money::ofMinor($minor, $currency)->format();
@endphp

<div class="mx-auto max-w-7xl px-4 py-12">
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::forecast.heading') }}</h1>
            <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('forecasting::forecast.subtitle') }}
            </p>
        </div>
        <a
            href="{{ route('settings') }}#forecast-buffers"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
        >{{ Lang::get('forecasting::forecast.adjust_buffers') }} &rarr;</a>
    </header>

    @if ($isEmpty)
        <section class="rounded-lg border border-slate-200 bg-white p-8 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::forecast.empty_heading') }}</h2>
            <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('forecasting::forecast.empty_body') }}
            </p>
            {{-- "/import" was not a route at all — the wizard is at
                 /imports/new — so the one link out of an empty forecast
                 landed on a 404, and a path spelled by hand is never
                 checked against the route table. --}}
            <p class="mt-3 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('forecasting::forecast.empty_start') }}
                <a href="{{ route('imports.new') }}" class="text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">{{ Lang::get('forecasting::forecast.empty_import_link') }}</a>
                {{ Lang::get('forecasting::forecast.empty_or') }}
                <a href="{{ route('recurring.review') }}" class="text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">{{ Lang::get('forecasting::forecast.empty_recurring_link') }}</a>.
            </p>
        </section>
    @else
        @if (count($accounts) > 0)
            <nav class="mb-6 flex flex-wrap items-center gap-2 border-b border-slate-200 dark:border-slate-700" role="tablist" aria-label="{{ Lang::get('forecasting::forecast.account_tablist') }}">
                <x-core::tab
                    :active="$isAllAccountsView"
                    id="forecast-account-tab-all"
                    aria-controls="forecast-account-panel"
                    wire:click="setAccount('all')"
                >{{ Lang::get('forecasting::forecast.all_accounts') }}</x-core::tab>
                @foreach ($accounts as $account)
                    <x-core::tab
                        :active="$selectedAccountId === $account['id']"
                        id="forecast-account-tab-{{ $account['id'] }}"
                        aria-controls="forecast-account-panel"
                        wire:click="setAccount('{{ $account['id'] }}')"
                    >{{ $account['name'] }}</x-core::tab>
                @endforeach
            </nav>
        @endif

        {{-- One panel for every account tab: only the selected account is
             ever rendered, so the tabs share one aria-controls target and
             the panel is named by whichever tab is selected. --}}
        <div
            @if (count($accounts) > 0)
                id="forecast-account-panel"
                role="tabpanel"
                aria-labelledby="forecast-account-tab-{{ $isAllAccountsView ? 'all' : $selectedAccountId }}"
            @endif
        >
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white p-1 dark:bg-slate-950 dark:border-slate-700" role="radiogroup" aria-label="{{ Lang::get('forecasting::forecast.horizon_label') }}">
                    @foreach (\Modules\Forecasting\Internal\Jobs\ProjectForecastJob::HORIZON_DAYS as $option)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $horizon === $option ? 'true' : 'false' }}"
                            wire:click="setHorizon({{ $option }})"
                            @class([
                                'rounded px-3 py-1.5 text-sm',
                                'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900' => $horizon === $option,
                                'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100' => $horizon !== $option,
                            ])
                        >{{ Lang::get('forecasting::forecast.n_days', ['days' => $option]) }}</button>
                    @endforeach
                </div>

                {{-- aria-pressed, not role="switch": a switch is the track-and-thumb
                     picture x-core::switch draws, and this is a chip in a row of
                     chips. "Switch, on" announced a control that is not here. --}}
                <button
                    type="button"
                    aria-pressed="{{ $viewByFunder ? 'true' : 'false' }}"
                    {{-- The name is the visible text; the explanation is a
                         description, so a screen reader announces the label
                         first and the rationale after it rather than instead. --}}
                    aria-describedby="view-by-funder-hint"
                    wire:click="toggleViewByFunder"
                    @class([
                        'inline-flex items-center gap-2 rounded-md border border-slate-200 dark:border-slate-700 px-3 py-1.5 text-sm',
                        'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900' => $viewByFunder,
                        'bg-white dark:bg-slate-950 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-900' => ! $viewByFunder,
                    ])
                >
                    <span>{{ Lang::get('forecasting::forecast.view_by_funder') }}</span>
                </button>
                <span id="view-by-funder-hint" class="sr-only">{{ Lang::get('forecasting::forecast.view_by_funder_hint') }}</span>
            </div>

            {{-- Scenario picker — baseline radio + one per saved scenario + "+ New scenario" chip. --}}
            <div class="mb-6 flex flex-wrap items-center gap-2" role="radiogroup" aria-label="{{ Lang::get('forecasting::forecast.scenario_group') }}">
                <button
                    type="button"
                    role="radio"
                    aria-checked="{{ $activeScenarioId === null ? 'true' : 'false' }}"
                    wire:click="setScenario(null)"
                    @class([
                        'rounded-md px-3 py-1 text-sm',
                        'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900' => $activeScenarioId === null,
                        'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' => $activeScenarioId !== null,
                    ])
                >{{ Lang::get('forecasting::forecast.baseline') }}</button>
                @foreach ($scenarios as $s)
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ $activeScenarioId === $s->id ? 'true' : 'false' }}"
                        wire:click="setScenario({{ $s->id }})"
                        @class([
                            'rounded-md px-3 py-1 text-sm',
                            'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900' => $activeScenarioId === $s->id,
                            'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' => $activeScenarioId !== $s->id,
                        ])
                    >{{ $s->name }}</button>
                @endforeach

                @if (! $creatingScenario)
                    <button
                        type="button"
                        wire:click="startCreateScenario"
                        @class([
                            'rounded-md px-3 py-1 text-sm',
                            'bg-emerald-600 dark:bg-emerald-500 text-white hover:bg-emerald-700 dark:hover:bg-emerald-400' => count($scenarios) === 0,
                            'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' => count($scenarios) > 0,
                        ])
                    >{{ Lang::get('forecasting::forecast.new_scenario') }}</button>
                @else
                    <div class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white p-1 dark:bg-slate-950 dark:border-slate-700">
                        <input
                            type="text"
                            wire:model.live.debounce.250ms="newScenarioName"
                            wire:keydown.enter.prevent="saveNewScenario"
                            placeholder="{{ Lang::get('forecasting::forecast.scenario_name_placeholder') }}"
                            aria-label="{{ Lang::get('forecasting::forecast.new_scenario_aria') }}"
                            class="block w-48 rounded-md border-0 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900"
                        >
                        <button
                            type="button"
                            wire:click="saveNewScenario"
                            class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                        >{{ Lang::get('forecasting::forecast.create_scenario') }}</button>
                        <button
                            type="button"
                            wire:click="cancelCreateScenario"
                            class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
                        >{{ Lang::get('forecasting::forecast.cancel') }}</button>
                    </div>
                    @if ($createScenarioError !== null)
                        <p class="ml-2 text-xs text-rose-700 dark:text-rose-500" role="alert">{{ $createScenarioError }}</p>
                    @endif
                @endif
            </div>

            {{-- All-accounts aggregate region. Renders a single-line EUR
                 rollup chart instead of a per-account rangeArea band. The
                 confidence legend hides on this tab since the aggregate has
                 no per-series identity. --}}
            @if ($isAllAccountsView)
                <section class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
                    <header class="mb-3">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::forecast.all_accounts') }} · {{ Lang::get('forecasting::forecast.baseline') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ Lang::get('forecasting::forecast.aggregate_subtitle', ['days' => $horizon]) }}
                        </p>
                    </header>

                    @include('forecasting::livewire.partials.aggregate-line-chart', [
                        'chartElementId' => $aggregateChartElementId,
                        'aggregatePoints' => $aggregatePoints,
                        'aggregateBufferFloor' => $aggregateBufferFloor,
                        'aggregateCurrency' => $aggregateCurrency,
                    ])
                </section>
            @endif

            {{-- Net diff tile + side-by-side region. --}}
            @if ($baseline instanceof ForecastDto)
                @if ($scenario instanceof ForecastDto)
                    @include('forecasting::livewire.partials.net-diff-tile', [
                        'netDiff' => $netDiff,
                        'netDiffCurrency' => $defaultCurrency,
                        'horizonDays' => \Modules\Forecasting\Internal\Jobs\ProjectForecastJob::HORIZON_DAYS,
                    ])
                @endif

                @php
                    $showConfidenceLegend = ! ($scenario instanceof ForecastDto) && count($baseline->seriesConfidence) >= 0;
                    $sidebarColumnClass = ($scenario instanceof ForecastDto) || $showConfidenceLegend
                        ? 'lg:grid-cols-[1fr_18rem]'
                        : '';
                @endphp
                <div class="grid grid-cols-1 gap-6 {{ $sidebarColumnClass }}">
                    <div class="grid grid-cols-1 gap-4 @if ($scenario instanceof ForecastDto) lg:grid-cols-2 @endif">
                        <section class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
                            <header class="mb-3 flex items-baseline justify-between gap-4">
                                <div>
                                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $selectedAccountName }} · {{ Lang::get('forecasting::forecast.baseline') }}</h2>
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                        {{ $eurFmt($todayBalanceMinor, $defaultCurrency) }} {{ Lang::get('forecasting::forecast.today') }}
                                        &nbsp;&rarr;&nbsp;
                                        {{ $eurFmt($horizonLowMinor, $defaultCurrency) }} &ndash; {{ $eurFmt($horizonHighMinor, $defaultCurrency) }} {{ Lang::get('forecasting::forecast.on_day') }} {{ $horizon }}
                                    </p>
                                </div>
                                @if ($selectedAccountId !== null && ! ($scenario instanceof ForecastDto))
                                    <div x-data="{ open: false }" class="relative">
                                        <button
                                            type="button"
                                            x-on:click="open = ! open"
                                            aria-haspopup="dialog"
                                            aria-label="{{ Lang::get('forecasting::forecast.edit_buffer_aria', ['name' => $selectedAccountName]) }}"
                                            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                                            style="font-variant-numeric: tabular-nums;"
                                        >
                                            {{ $effectiveBufferMinor === null ? Lang::get('forecasting::forecast.buffer_not_set') : Lang::get('forecasting::forecast.buffer_set', ['amount' => $eurFmt($effectiveBufferMinor, $selectedAccountCurrency)]) }}
                                        </button>
                                        <div
                                            x-show="open"
                                            x-cloak
                                            x-on:click.outside="open = false"
                                            x-on:keydown.escape.window="open = false"
                                            x-on:buffer-editor:saved.window="open = false"
                                            class="absolute right-0 z-10 mt-1 w-64 rounded-md border border-slate-200 bg-white p-3 text-sm shadow-lg dark:bg-slate-950 dark:border-slate-700"
                                        >
                                            @livewire('forecasting.account-buffer-editor', [
                                                'accountId' => $selectedAccountId,
                                                'currentBufferMinor' => $effectiveBufferMinor,
                                                'currency' => $selectedAccountCurrency,
                                                'accountName' => $selectedAccountName,
                                            ], key('buffer-editor-' . $selectedAccountId))
                                        </div>
                                    </div>
                                @endif
                            </header>

                            @foreach ($shortfallWindows as $window)
                                <p class="mb-2 flex items-center gap-1 text-xs text-rose-700 dark:text-rose-500" style="font-variant-numeric: tabular-nums;">
                                    <span aria-hidden="true">↘</span>
                                    <span>
                                        {{ Lang::get('forecasting::forecast.shortfall', [
                                            'date' => $window->startsAt->translatedFormat('d M'),
                                            'amount' => $eurFmt(abs($window->lowestBalanceMinor - $window->bufferUsedMinor), $window->currency),
                                            'buffer' => $eurFmt($window->bufferUsedMinor, $window->currency),
                                        ]) }}
                                    </span>
                                </p>
                            @endforeach

                            @include('forecasting::livewire.partials.range-area-chart', [
                                'forecast' => $baseline,
                                'panel' => 'baseline',
                                'apexOptions' => $apexOptions,
                                'chartElementId' => $chartElementId,
                            ])
                        </section>

                        @if ($scenario instanceof ForecastDto)
                            <section class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
                                <header class="mb-3 flex items-baseline justify-between gap-4">
                                    <div>
                                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $selectedAccountName }} · {{ Lang::get('forecasting::forecast.scenario_word') }}</h2>
                                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                            {{ Lang::get('forecasting::forecast.compared_against_baseline') }}
                                        </p>
                                    </div>
                                </header>

                                @include('forecasting::livewire.partials.range-area-chart', [
                                    'forecast' => $scenario,
                                    'panel' => 'scenario',
                                    'apexOptions' => $scenarioApexOptions,
                                    'chartElementId' => $scenarioChartElementId,
                                ])
                            </section>
                        @endif
                    </div>

                    @if ($scenario instanceof ForecastDto)
                        {{-- Named because a page may carry more than one complementary
                             landmark, and a list of unlabelled "complementary" entries
                             tells a screen-reader user nothing about which is which. --}}
                        <aside aria-label="{{ Lang::get('forecasting::forecast.scenario_editor_aria') }}" class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
                            @livewire('forecasting.scenario-editor-sidebar', [
                                'scenarioId' => $activeScenarioId,
                            ], key('scenario-sidebar-' . $activeScenarioId))
                        </aside>
                    @elseif ($showConfidenceLegend)
                        <aside aria-labelledby="confidence-legend-heading" class="rounded-lg border border-slate-200 bg-white p-4 space-y-2 dark:bg-slate-950 dark:border-slate-700" data-testid="confidence-legend">
                            <h3 id="confidence-legend-heading" class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ Lang::get('forecasting::forecast.series_confidence') }}</h3>
                            @if (count($baseline->seriesConfidence) === 0)
                                {{-- {!! !!}: app-static copy with an apostrophe; the original
                                     literal text node was unescaped, so keep the raw render to
                                     preserve byte-identical output (no user data flows here). --}}
                                <p class="text-xs text-slate-500 dark:text-slate-400">{!! Lang::get('forecasting::forecast.no_series_contribute') !!}</p>
                            @else
                                <ul class="space-y-1">
                                    @foreach ($baseline->seriesConfidence as $confidence)
                                        @include('forecasting::livewire.partials.series-confidence-row', ['confidence' => $confidence])
                                    @endforeach
                                </ul>
                            @endif
                        </aside>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
