{{--
    Report builder `donut` viz partial (Req 8) — the only viz using the
    flat `series: [n1, n2, ...]` + top-level `labels: [...]` ApexCharts
    shape (bar/line use the `{name, data}` series shape). Clones the Alpine
    x-init + data-options mount block verbatim from
    Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php.

    Variables in scope:
      $chartElementId : string
      $rows           : list<Modules\Reports\Public\Dto\ReportResultRow>
      $drilldownUrls  : list<string>  — parallel to $rows, one URL per segment (Req 12)
      $metricLabel    : string
--}}
@php
    $labels = array_map(static fn ($row) => $row->groupLabel, $rows);
    // ApexCharts donut series expects non-negative magnitudes; a report
    // total is signed (spend renders as a negative settled amount), so the
    // slice size is the absolute value while the table/total elsewhere
    // still shows the true signed figure.
    $series = array_map(static fn ($row) => abs($row->amountMinor) / 100, $rows);

    $palette = ['#0F172A', '#334155', '#64748B', '#94A3B8', '#0EA5E9', '#059669', '#B45309', '#BE123C', '#7C3AED', '#0891B2'];
    $colors = [];
    foreach ($labels as $i => $label) {
        $colors[] = $palette[$i % count($palette)];
    }

    $options = [
        'chart' => [
            'type' => 'donut',
            'height' => 320,
            'animations' => ['enabled' => false],
            'toolbar' => ['show' => false],
            'fontFamily' => 'Inter, system-ui, sans-serif',
        ],
        'series' => $series,
        'labels' => $labels,
        'colors' => $colors,
        'dataLabels' => ['enabled' => false],
        'legend' => [
            'show' => true,
            'position' => 'bottom',
            'fontSize' => '12px',
            'labels' => ['colors' => '#64748B'],
        ],
        'tooltip' => ['enabled' => true],
        'beatraxDrilldownUrls' => $drilldownUrls,
        'responsive' => [
            [
                'breakpoint' => 768,
                'options' => [
                    'chart' => ['height' => 260],
                    'legend' => ['position' => 'bottom', 'fontSize' => '11px'],
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
                const idx = config.dataPointIndex;
                const url = drilldownUrls[idx];
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
        data-chart-variant="donut"
        class="min-h-[320px] rounded-md border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
        title="Click a segment to view its transactions"
    ></div>
</div>
