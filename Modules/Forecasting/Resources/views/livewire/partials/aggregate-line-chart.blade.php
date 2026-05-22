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

@php
    $aggData = array_map(
        static fn (array $p): array => ['x' => $p['date'], 'y' => $p['point_minor'] / 100],
        $aggregatePoints,
    );
    $yMin = $aggData === [] ? 0 : min(array_map(static fn ($p) => $p['y'], $aggData)) - 1;
    $yMax = $aggData === [] ? 0 : max(array_map(static fn ($p) => $p['y'], $aggData)) + 1;
    $bufferValue = $aggregateBufferFloor / 100;

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
        'annotations' => $aggregateBufferFloor > 0 ? [
            'yaxis' => [
                [
                    'y' => $yMin - 1,
                    'y2' => $bufferValue,
                    'fillColor' => '#FECDD3',
                    'opacity' => 0.4,
                    'label' => ['text' => '', 'position' => 'left'],
                ],
            ],
        ] : [],
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

<div
    id="{{ $chartElementId }}"
    data-testid="all-accounts-aggregate-chart"
    data-chart-variant="line"
    data-options="{{ $optionsJson }}"
    class="min-h-[320px] rounded-md border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
></div>
