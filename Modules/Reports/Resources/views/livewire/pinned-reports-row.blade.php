@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Reports\Internal\Enums\ReportViz')
{{--
    Dashboard "pinned reports" mini-card row. Up to 3 chart-only
    mini cards, ADDITIVE among the dashboard's other fixed cards — same
    card chrome as the neighboring goals/tax/budgets tiles (rounded-lg
    border-only, no shadow), reduced ~180-200px chart height, no control
    rail/table. The whole card is a single anchor to the full report at
    `/reports?report={id}` (no per-data-point drill-down here, unlike the
    full builder's charts). Renders NOTHING when the user has zero pins —
    the outer root <div> stays present (Livewire requires one root element
    per render) but empty/invisible.

    Variables in scope:
      $cards : list<array{id: int, name: string, chartElementId: string, optionsJson: string}>
--}}
<div>
    @if (count($cards) > 0)
        <div role="group" class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="{{ Lang::get('reports::pinned.reports_aria') }}">
            @foreach ($cards as $card)
                {{-- ApexCharts sizes its own bottom legend at half the chart box
                     and scrolls whatever will not fit — a 120px window onto every
                     category name, centre-justified and ragged. The donut's key is
                     lifted out of the chart and rendered below as ordinary card
                     content, so it wraps and takes the room it needs.

                     The x axis is given `trim: false` for the same reason. At this
                     card's width ApexCharts truncates every tick to fit — five
                     months of history rendered as "Apr 2…", "May 2…", which reads
                     as a day of the month rather than a year. Hiding the ticks
                     that would collide keeps the ones it does draw complete. --}}
                @php
                    $options = json_decode($card['optionsJson'], true);
                    $legend = [];
                    if (is_array($options) && ($options['chart']['type'] ?? '') === ReportViz::Donut->value) {
                        foreach ($options['labels'] ?? [] as $i => $label) {
                            $legend[] = ['label' => $label, 'colour' => $options['colors'][$i] ?? '#94A3B8'];
                        }
                    }
                @endphp
                <a
                    href="{{ Destination::Reports->url(['report' => $card['id']]) }}"
                    class="block rounded-lg border border-slate-200 bg-white p-4 transition-colors hover:border-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-slate-600"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="min-w-0 flex-1 truncate text-base font-semibold text-slate-900 dark:text-slate-100">{{ $card['name'] }}</p>
                        {{-- role="img" or the aria-label is dropped: a generic element takes no name. --}}
                        <span role="img" class="shrink-0 text-sm text-slate-400 dark:text-slate-500" aria-label="{{ Lang::get('reports::pinned.pinned_report') }}" title="{{ Lang::get('reports::pinned.pinned_report') }}">📌</span>
                    </div>

                    <div
                        style="width:100%"
                        class="mt-2"
                        x-data="{ chart: null }"
                        x-init="
                            if (! window.ApexCharts) { return; }
                            const opts = window.beatraxApplyChartTheme(JSON.parse($el.dataset.options));
                            @if ($legend !== []) opts.legend = Object.assign({}, opts.legend, { show: false }); @endif
                            opts.xaxis = Object.assign({}, opts.xaxis, {
                                labels: Object.assign({}, (opts.xaxis || {}).labels, { trim: false, hideOverlappingLabels: true }),
                            });
                            chart = new window.ApexCharts($el.querySelector('#{{ $card['chartElementId'] }}'), opts);
                            chart.render();
                        "
                        data-options="{{ $card['optionsJson'] }}"
                    >
                        <div
                            id="{{ $card['chartElementId'] }}"
                            data-testid="pinned-report-chart"
                            class="min-h-[180px]"
                        ></div>
                    </div>

                    @if ($legend !== [])
                        <ul class="mt-3 flex flex-wrap gap-x-3 gap-y-1" data-testid="pinned-report-legend">
                            @foreach ($legend as $entry)
                                <li class="flex min-w-0 items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $entry['colour'] }};" aria-hidden="true"></span>
                                    <span class="truncate">{{ $entry['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
