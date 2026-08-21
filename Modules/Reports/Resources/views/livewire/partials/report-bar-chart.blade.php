@use('Modules\Core\Public\Support\Lang')
{{--
    Report builder `bar` viz partial. Clones the Alpine x-init +
    data-options ApexCharts mount block verbatim from
    Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php
    (this codebase's ONLY charting mechanism) — only `chart.type` and the
    `series`/`labels` shape vary per viz partial.

    Variables in scope:
      $chartElementId : string
      $rows           : list<Modules\Reports\Internal\Dto\ReportResultRow>
      $drilldownUrls  : list<string>  — parallel to $rows, one URL per bar
      $metricLabel    : string
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    $categories = array_map(static fn ($row) => $row->groupLabel, $rows);
    $data = array_map(static fn ($row) => $row->amountMinor / Money::MINOR_UNITS_PER_MAJOR, $rows);

    $options = [
        'chart' => [
            'type' => 'bar',
            'height' => 320,
            'animations' => ['enabled' => false],
            'toolbar' => ['show' => false],
            'zoom' => ['enabled' => false],
            'fontFamily' => 'Inter, system-ui, sans-serif',
        ],
        'series' => [
            ['name' => $metricLabel, 'data' => $data],
        ],
        'plotOptions' => [
            'bar' => ['borderRadius' => 3, 'columnWidth' => '55%'],
        ],
        'colors' => ['#0F172A'],
        'dataLabels' => ['enabled' => false],
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
        'tooltip' => ['shared' => false, 'intersect' => true],
        // Parallel array of drill-down URLs, one per bar/category index
        // (Req 12/D-03) — read by the dataPointSelection handler below,
        // never a hardcoded '/search' navigation (Pitfall 3).
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

{{--
    `buildOptions()` re-reads `data-options` (and re-attaches the
    dataPointSelection drilldown handler off the FRESH `beatraxDrilldownUrls`
    array) every time it's called — used both at `x-init` mount and again on
    every `report-updated` browser event (dispatched by
    `ReportBuilder::updated()` after any control-rail change), so
    `chart.updateOptions()` refreshes both the chart's data AND its
    click-through targets in place, without remounting the component. A
    naive re-mount-only refresh (as Forecasting's simpler partials do) would
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
                    const url = drilldownUrls[config.dataPointIndex];
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
        if (! window.ApexCharts || ! chart) { return; }
        chart.updateOptions(buildOptions(), true, false);
    "
    data-options="{{ $optionsJson }}"
>
    <div
        id="{{ $chartElementId }}"
        data-testid="report-chart"
        data-chart-variant="bar"
        class="min-h-[320px] rounded-md border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
        title="{{ Lang::get('reports::builder.chart.bar_title') }}"
    ></div>
</div>
