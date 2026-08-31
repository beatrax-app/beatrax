@use('Modules\Core\Public\Support\Lang')
{{--
    Report builder `donut` viz partial — the only viz using the
    flat `series: [n1, n2, ...]` + top-level `labels: [...]` ApexCharts
    shape (bar/line use the `{name, data}` series shape). Clones the Alpine
    x-init + data-options mount block verbatim from
    Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php.

    Variables in scope:
      $chartElementId : string
      $rows           : list<Modules\Reports\Internal\Dto\ReportResultRow>
      $chartCurrency  : string        — the one currency this ring is drawn in
      $drilldownUrls  : list<string>  — parallel to $rows, one URL per segment
      $metricLabel    : string
--}}
@use('Modules\Reports\Internal\Support\ChartAmount')
@use('Modules\Reports\Internal\Support\DonutPalette')
@php
    $labels = array_map(static fn ($row) => $row->groupLabel, $rows);
    // $rows already moves the one way this ring can draw (ChartSeries), so the
    // magnitude here is the row's own size and never a credit flipped to read
    // as spending. What was left out is named beside the chart.
    $series = ChartAmount::magnitudes($rows);

    $colors = DonutPalette::forSlices(count($labels));

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
        // The tooltip is money, and which money only this partial knows.
        'beatraxCurrency' => $chartCurrency,
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

{{--
    `buildOptions()` re-reads `data-options` (and re-attaches the
    dataPointSelection drilldown handler off the FRESH `beatraxDrilldownUrls`
    array) every time it's called — used both at `x-init` mount and again on
    every `report-updated` browser event (dispatched by
    `ReportBuilder::updated()` after any control-rail change). Unlike the
    axis-based bar/line partials (which call `chart.updateOptions()`), the
    axis-less donut destroys+recreates its ApexCharts instance on refresh —
    `updateOptions()` throws on the donut's missing axis internals — which
    refreshes both the slice data AND the drilldown click-through targets.
    A naive re-mount-only refresh (as Forecasting's simpler partials do) would
    leave the click handler bound to the stale drilldown-url array.
--}}
<div
    style="width:100%"
    x-data="{
        chart: null,
        buildOptions() {
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
            return opts;
        },
    }"
    x-init="
        if (! window.ApexCharts) { return; }
        chart = new window.ApexCharts($el.querySelector('#{{ $chartElementId }}'), buildOptions());
        chart.render();
    "
    x-on:report-updated.window="
        if (! window.ApexCharts) { return; }
        if (chart) { chart.destroy(); }
        chart = new window.ApexCharts($el.querySelector('#{{ $chartElementId }}'), buildOptions());
        chart.render();
    "
    data-options="{{ $optionsJson }}"
>
    <div
        id="{{ $chartElementId }}"
        data-testid="report-chart"
        data-chart-variant="donut"
        class="min-h-[320px] rounded-md border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
        title="{{ Lang::get('reports::builder.chart.donut_title') }}"
    ></div>
</div>
