{{--
    All-accounts aggregate `line` chart partial.

    Renders ONE line series summing every account's per-day point
    estimate (converted to EUR via the per-account default-currency
    stored fx-rate on each contribution). The chart variant is
    `line` (NOT `rangeArea`) — the aggregate of all accounts is
    informational; the per-account chart still shows the honest
    range band on its own tab.

    Variables in scope:
      - $chartElementId : string                   (DOM id the JS hook attaches to)
      - $aggregatePoints : list<array{date: string, point_minor: int}>
      - $aggregateBufferFloor : int                (sum of per-account effective buffers, in minor)
      - $aggregateCurrency : string                ('EUR' for the rollup)
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')

@php
    $aggData = array_map(
        static fn (array $p): array => ['x' => $p['date'], 'y' => $p['point_minor'] / Money::MINOR_UNITS_PER_MAJOR],
        $aggregatePoints,
    );
    $yMin = $aggData === [] ? 0 : min(array_map(static fn ($p) => $p['y'], $aggData)) - 1;
    $yMax = $aggData === [] ? 0 : max(array_map(static fn ($p) => $p['y'], $aggData)) + 1;
    $bufferValue = $aggregateBufferFloor / Money::MINOR_UNITS_PER_MAJOR;

    $options = [
        'chart' => [
            'type' => 'line',
            'height' => 320,
            'animations' => ['enabled' => false],
            'toolbar' => ['show' => false],
            'zoom' => ['enabled' => false],
            'fontFamily' => 'Inter, system-ui, sans-serif',
        ],
        'series' => [
            ['name' => 'Total balance', 'type' => 'line', 'data' => $aggData],
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
        // D-11: phone-tuned responsive breakpoints baked into server-rendered
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
{{-- D-11: width:100% ensures the chart fills the container column at all
     viewport widths including phone. The responsive[] breakpoints above
     handle tick/label tuning at <768px. --}}
<div
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
    <div
        id="{{ $chartElementId }}"
        data-testid="all-accounts-aggregate-chart"
        data-chart-variant="line"
        class="min-h-[320px] rounded-md border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
    ></div>
</div>
