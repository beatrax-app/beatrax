{{--
    All-accounts aggregate `line` chart partial.

    Renders ONE line series summing every account's per-day point
    estimate, each converted from the account's own currency into the
    reader's base currency before it is added. The chart variant is
    `line` (NOT `rangeArea`) — the aggregate of all accounts is
    informational; the per-account chart still shows the honest
    range band on its own tab.

    Variables in scope:
      - $chartElementId : string                   (DOM id the JS hook attaches to)
      - $aggregatePoints : list<array{date: string, point_minor: int}>
      - $aggregateBufferFloor : int                (per-account effective buffers in base currency, in minor)
      - $aggregateCurrency : string                (the reader's base currency)
      - $chartTestId : string                      (data-testid; the baseline and
        scenario roll-ups both render this partial and a shared id would make
        an assertion about one of them true of the other)
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Forecasting\Internal\Support\ChartAxisBounds')

@use('Modules\Core\Public\Support\Lang')
@php
    $aggData = array_map(
        static fn (array $p): array => ['x' => $p['date'], 'y' => Money::majorUnits($p['point_minor'], $aggregateCurrency)],
        $aggregatePoints,
    );
    [$yMin, $yMax] = ChartAxisBounds::spanning(
        $aggData === [] ? 0.0 : (float) min(array_map(static fn ($p) => $p['y'], $aggData)),
        $aggData === [] ? 0.0 : (float) max(array_map(static fn ($p) => $p['y'], $aggData)),
    );
    $bufferValue = Money::majorUnits($aggregateBufferFloor, $aggregateCurrency);

    $options = [
        // The axis formatter in app.js has no other way to know what these
        // points are denominated in; without it the page-level reporting
        // currency wins and the numbers keep the wrong symbol.
        'beatraxCurrency' => $aggregateCurrency,
        'chart' => [
            'type' => 'line',
            'height' => 320,
            'animations' => ['enabled' => false],
            'toolbar' => ['show' => false],
            'zoom' => ['enabled' => false],
            'fontFamily' => 'Inter, system-ui, sans-serif',
        ],
        'series' => [
            ['name' => Lang::get('forecasting::forecast.total_balance'), 'type' => 'line', 'data' => $aggData],
        ],
        'stroke' => ['curve' => 'straight', 'width' => 2.5],
        'colors' => ['#0F172A'],
        // ApexCharts v5 requires the full annotations object shape: a bare []
        // serializes to a JSON array, clobbers the library's annotation
        // defaults, and crashes drawImageAnnos on annotations.images.
        'annotations' => [
            'yaxis' => $aggregateBufferFloor > 0 ? [
                [
                    'y' => $yMin - 1,
                    'y2' => $bufferValue,
                    'fillColor' => '#FECDD3',
                    'opacity' => 0.4,
                    'label' => ['text' => '', 'position' => 'left'],
                ],
            ] : [],
            'xaxis' => [],
            'points' => [],
            'images' => [],
        ],
        'xaxis' => [
            'type' => 'datetime',
            'labels' => ['style' => ['fontSize' => '12px', 'colors' => '#64748B']],
        ],
        'yaxis' => [
            'min' => $yMin,
            'max' => $yMax,
            'forceNiceScale' => true,
            'labels' => ['style' => ['fontSize' => '12px', 'colors' => '#64748B']],
        ],
        'grid' => ['borderColor' => '#E2E8F0'],
        'legend' => ['show' => false],
        'tooltip' => ['shared' => true, 'intersect' => false],
        // Phone-tuned responsive breakpoints baked into server-rendered
        // options — chart fills the container at phone width with fewer x-axis
        // labels and hidden legend; tooltip stays active on touch.
        'responsive' => [
            [
                'breakpoint' => 768,
                'options' => [
                    'chart' => ['height' => 240],
                    'xaxis' => ['tickAmount' => 4],
                    'legend' => ['show' => false],
                ],
            ],
        ],
    ];

    // Encode the options once with JSON flags that align with the
    // sibling range-area-chart partial. Double-quoting the attribute
    // and printing the encoded payload through Blade's {{ }} escaping
    // means a literal single-quote inside any future $options value
    // (e.g. an account name with an apostrophe) cannot break out of
    // the attribute.
    $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($optionsJson === false) {
        $optionsJson = '{}';
    }
@endphp

{{--
    Same Alpine `x-data` / `x-init` hydration pair the sibling
    `range-area-chart.blade.php` uses — without it, the inner
    `<div id="{{ $chartElementId }}">` renders as a bare empty box
    because nothing mounts an ApexCharts instance onto it. The
    forecast-updated event listener mirrors the range-area chart so
    a Livewire-driven horizon / scenario flip refreshes the line
    chart without remounting the component.
--}}
{{-- width:100% ensures the chart fills the container column at all
     viewport widths including phone. The responsive[] breakpoints above
     handle tick/label tuning at <768px. --}}
<div
    {{-- Keyed on the chart id: a horizon or account flip renames the
         target, and without this Livewire morphs the wrapper in place,
         x-init never re-runs, and the Alpine instance goes on holding a
         node that is no longer the one being drawn into. --}}
    wire:key="chart-{{ $chartElementId }}"
    style="width:100%"
    x-data="{ chart: null }"
    x-init="
        if (! window.ApexCharts) { return; }
        chart = new window.ApexCharts(
            $el.querySelector('#{{ $chartElementId }}'),
            window.beatraxApplyChartTheme(JSON.parse($el.dataset.options)),
        );
        chart.render();
    "
    x-on:forecast-updated.window="
        if (! window.ApexCharts || ! chart) { return; }
        chart.updateOptions(window.beatraxApplyChartTheme(JSON.parse($el.dataset.options)), true, false);
    "
    data-options="{{ $optionsJson }}"
>
    {{-- wire:ignore, and it is not decoration: Livewire's morph wiped the
         rendered SVG out of this node, leaving a bordered empty box. --}}
    <div
        wire:ignore
        id="{{ $chartElementId }}"
        data-testid="{{ $chartTestId }}"
        data-chart-variant="line"
        class="min-h-[320px] rounded-md border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
    ></div>
</div>
