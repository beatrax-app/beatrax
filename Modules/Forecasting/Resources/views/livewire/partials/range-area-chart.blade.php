{{--
    Per-account rangeArea + line combo chart partial.

    Mount target: a <div id="{{ $chartElementId }}"></div> hydrated
    client-side by ApexCharts via the Alpine `x-data` / `x-init` pair.

    The `data-options` attribute carries a JSON-encoded ApexCharts
    options shape; the Alpine block decodes it on init and re-renders
    via `chart.updateOptions(newOptions, true, false)` on the
    `forecast-updated` browser event so a Livewire-driven horizon /
    account flip refreshes the chart without re-mounting the
    component.

    The container intentionally avoids `wire:ignore`. The pattern is:
    Livewire owns the data-options attribute (string-in, string-out),
    Alpine reads it once and re-reads on browser-event re-render
    cycles. No DOM-survive-refresh contract is required here.

    Loading state: when `$forecast->isComputing` is true, the chart
    container is opacity-dimmed and an "Updating…" caption is
    rendered absolutely-positioned in the top-right corner.

    No-JS noscript fallback: the chart requires JavaScript; if it is
    disabled the partial renders a one-line explanation so the page
    is not silently empty.
--}}
@use('Modules\Core\Public\Support\Lang')
@php
    $optionsJson = json_encode($apexOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($optionsJson === false) {
        $optionsJson = '{}';
    }
@endphp

{{-- width:100% ensures the chart fills the container column at all
     viewport widths including phone. The responsive[] breakpoints in the
     server-rendered data-options handle tick/label tuning at <768px. --}}
<div
    @class([
        'relative',
        'opacity-60 pointer-events-none' => $forecast->isComputing,
    ])
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
    @if ($forecast->isComputing)
        {{--
            The wire:poll element is CONDITIONALLY rendered so that
            when the projection status flips from pending/running to
            complete the next Livewire diff unmounts this node and
            polling halts automatically.
        --}}
        <div wire:poll.2s="refreshProjectionStatus" class="absolute right-2 top-2 z-10">
            <p class="text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ Lang::get('forecasting::forecast.updating') }}&hellip;</p>
        </div>
    @endif
    <div id="{{ $chartElementId }}"></div>
    <noscript>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            {{ Lang::choice('forecasting::forecast.chart_noscript', $forecast->horizonDays, ['days' => $forecast->horizonDays]) }}
        </p>
    </noscript>
</div>
