{{--
    Report builder `line` viz partial (Req 8) — default for time-series
    reports (net_worth metric or time_bucket dimension). Clones the Alpine
    x-init + data-options ApexCharts mount block verbatim from
    Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php.

    Variables in scope:
      $chartElementId : string
      $rows           : list<Modules\Reports\Public\Dto\ReportResultRow>
      $drilldownUrls  : list<string>  — parallel to $rows, one URL per point (Req 12)
      $metricLabel    : string
--}}
@php
    $categories = array_map(static fn ($row) => $row->groupLabel, $rows);
    $data = array_map(static fn ($row) => $row->amountMinor / 100, $rows);

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
            ['name' => $metricLabel, 'type' => 'line', 'data' => $data],
        ],
        'stroke' => ['curve' => 'straight', 'width' => 2.5],
        'markers' => ['size' => 4],
        'colors' => ['#0F172A'],
        'xaxis' => [
            'categories' => $categories,
            'labels' => ['style' => ['fontSize' => '12px', 'colors' => '#64748B']],
        ],
        'yaxis' => [
            'forceNiceScale' => true,
            'labels' => ['style' => ['fontSize' => '12px', 'colors' => '#64748B']],
        ],
        'grid' => ['borderColor' => '#E2E8F0'],
        'legend' => ['show' => false],
        'tooltip' => ['shared' => true, 'intersect' => false],
        'beatraxDrilldownUrls' => $drilldownUrls,
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

    $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($optionsJson === false) {
        $optionsJson = '{}';
    }
@endphp

<div
    style="width:100%"
    x-data="{ chart: null }"
    x-init="
        if (! window.ApexCharts) { return; }
        const opts = window.beatraxApplyChartTheme(JSON.parse($el.dataset.options));
        const drilldownUrls = Array.isArray(opts.beatraxDrilldownUrls) ? opts.beatraxDrilldownUrls : [];
        opts.chart = opts.chart || {};
        opts.chart.events = Object.assign({}, opts.chart.events || {}, {
            dataPointSelection(event, chartContext, config) {
                const url = drilldownUrls[config.dataPointIndex];
                if (url) { window.location = url; }
            },
        });
        chart = new window.ApexCharts($el.querySelector('#{{ $chartElementId }}'), opts);
        chart.render();
    "
    data-options="{{ $optionsJson }}"
>
    <div
        id="{{ $chartElementId }}"
        data-testid="report-chart"
        data-chart-variant="line"
        class="min-h-[320px] rounded-md border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
        title="Click a point to view its transactions"
    ></div>
</div>
