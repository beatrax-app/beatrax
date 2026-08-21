@use('Modules\Core\Public\Support\Lang')
{{--
    Recurring series detail chart options partial.

    Merges the server-rendered $apexOptions with phone-tuned
    responsive breakpoints and encodes the result as a JSON string
    for the chart's `data-options` attribute.

    Variables in scope:
      - $apexOptions : array<string, mixed>  — chart options from
        RecurringSeriesDetailPage::buildApexOptions()
      - $chartElementId : string             — DOM id the JS hook attaches to

    The responsive[] array is baked in here (not in the PHP class) so the
    presentation concern (phone tuning) stays in the view layer while the
    series/data concern stays in the Livewire component.
--}}
@php
    // Inject phone-tuned responsive breakpoints into the server-rendered
    // options so the chart fills the container at phone width with fewer x-axis
    // labels and hidden legend. The tooltip stays active on touch.
    $recurringDetailOptions = array_merge($apexOptions, [
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
    ]);
    $recurringDetailOptionsJson = json_encode(
        $recurringDetailOptions,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    if ($recurringDetailOptionsJson === false) {
        $recurringDetailOptionsJson = '{}';
    }
@endphp

{{-- width:100% ensures the chart fills the container column at all
     viewport widths including phone. --}}
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
    data-options="{{ $recurringDetailOptionsJson }}"
>
    <div id="{{ $chartElementId }}"></div>
    <noscript>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            {{ Lang::get('recurring::detail.chart_requires_js') }}
        </p>
    </noscript>
</div>
