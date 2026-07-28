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
@php
    use Modules\Forecasting\Public\Dto\ForecastDto;

    $eurFmt = static function (int $minor, string $currency = 'EUR'): string {
        $value = $minor / 100;
        $formatter = new \NumberFormatter('nl_NL', \NumberFormatter::CURRENCY);
        $rendered = $formatter->formatCurrency($value, $currency);

        return $rendered === false ? number_format($value, 2, ',', '.') : $rendered;
    };
@endphp

<div class="mx-auto max-w-7xl px-4 py-12">
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Forecast</h1>
            <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                Where your balance is heading — over the next 30 to 365 days.
            </p>
        </div>
        <a
            href="{{ url('/settings') }}#forecast-buffers"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
        >Adjust buffers &rarr;</a>
    </header>

    @if ($isEmpty)
        <section class="rounded-lg border border-slate-200 bg-white p-8 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">No forecast data yet</h2>
            <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                Connect an account or approve a recurring series to see your projected balance over the coming weeks.
            </p>
            <p class="mt-3 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                Start by
                <a href="{{ url('/import') }}" class="text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">importing a statement</a>
                or
                <a href="{{ url('/recurring/review') }}" class="text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">reviewing recurring patterns</a>.
            </p>
        </section>
    @else
        @if (count($accounts) > 0)
            <nav class="mb-6 flex flex-wrap items-center gap-2 border-b border-slate-200 dark:border-slate-700" role="tablist" aria-label="Account">
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $isAllAccountsView ? 'true' : 'false' }}"
                    wire:click="setAccount('all')"
                    @class([
                        'px-3 py-2 text-sm',
                        'border-b-2 border-slate-900 dark:border-slate-100 font-medium text-slate-900 dark:text-slate-100' => $isAllAccountsView,
                        'border-b-2 border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100' => ! $isAllAccountsView,
                    ])
                >All accounts</button>
                @foreach ($accounts as $account)
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $selectedAccountId === $account['id'] ? 'true' : 'false' }}"
                        wire:click="setAccount('{{ $account['id'] }}')"
                        @class([
                            'px-3 py-2 text-sm',
                            'border-b-2 border-slate-900 dark:border-slate-100 font-medium text-slate-900 dark:text-slate-100' => $selectedAccountId === $account['id'],
                            'border-b-2 border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100' => $selectedAccountId !== $account['id'],
                        ])
                    >{{ $account['name'] }}</button>
                @endforeach
            </nav>
        @endif

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white p-1 dark:bg-slate-950 dark:border-slate-700" role="radiogroup" aria-label="Forecast horizon">
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
                    >{{ $option }} days</button>
                @endforeach
            </div>

            <button
                type="button"
                role="switch"
                aria-checked="{{ $viewByFunder ? 'true' : 'false' }}"
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
                <span>View by funder</span>
            </button>
            <span id="view-by-funder-hint" class="sr-only">Collapse chain-resolved series onto the account that actually pays them.</span>
        </div>

        {{-- Scenario picker — baseline radio + one per saved scenario + "+ New scenario" chip. --}}
        <div class="mb-6 flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Scenario">
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
            >Baseline</button>
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
                >+ New scenario</button>
            @else
                <div class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white p-1 dark:bg-slate-950 dark:border-slate-700">
                    <input
                        type="text"
                        wire:model.live.debounce.250ms="newScenarioName"
                        wire:keydown.enter.prevent="saveNewScenario"
                        placeholder="Scenario name"
                        aria-label="New scenario name"
                        class="block w-48 rounded-md border-0 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900"
                    >
                    <button
                        type="button"
                        wire:click="saveNewScenario"
                        class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                    >Create scenario</button>
                    <button
                        type="button"
                        wire:click="cancelCreateScenario"
                        class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
                    >Cancel</button>
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
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">All accounts · Baseline</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Combined balance across every account, projected over the next {{ $horizon }} days.
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
                                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $selectedAccountName }} · Baseline</h2>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                    {{ $eurFmt($todayBalanceMinor, $defaultCurrency) }} today
                                    &nbsp;&rarr;&nbsp;
                                    {{ $eurFmt($horizonLowMinor, $defaultCurrency) }} &ndash; {{ $eurFmt($horizonHighMinor, $defaultCurrency) }} on day {{ $horizon }}
                                </p>
                            </div>
                            @if ($selectedAccountId !== null && ! ($scenario instanceof ForecastDto))
                                <div x-data="{ open: false }" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        aria-haspopup="dialog"
                                        aria-label="Edit minimum buffer for {{ $selectedAccountName }}"
                                        class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                                        style="font-variant-numeric: tabular-nums;"
                                    >
                                        {{ $effectiveBufferMinor === null ? 'Buffer: not set' : 'Buffer: ' . $eurFmt($effectiveBufferMinor, $selectedAccountCurrency) }}
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
                                    Shortfall starts {{ $window->startsAt->format('d M') }} —
                                    {{ $eurFmt(abs($window->lowestBalanceMinor - $window->bufferUsedMinor), $window->currency) }}
                                    below your {{ $eurFmt($window->bufferUsedMinor, $window->currency) }} buffer
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
                                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $selectedAccountName }} · Scenario</h2>
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                        Compared against baseline above
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
                    <aside aria-label="Scenario editor" class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
                        @livewire('forecasting.scenario-editor-sidebar', [
                            'scenarioId' => $activeScenarioId,
                        ], key('scenario-sidebar-' . $activeScenarioId))
                    </aside>
                @elseif ($showConfidenceLegend)
                    <aside aria-labelledby="confidence-legend-heading" class="rounded-lg border border-slate-200 bg-white p-4 space-y-2 dark:bg-slate-950 dark:border-slate-700" data-testid="confidence-legend">
                        <h3 id="confidence-legend-heading" class="text-sm font-medium text-slate-700 dark:text-slate-300">Series confidence</h3>
                        @if (count($baseline->seriesConfidence) === 0)
                            <p class="text-xs text-slate-500 dark:text-slate-400">No series contribute to this account's forecast yet.</p>
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
    @endif
</div>
