{{--
    Dashboard "pinned reports" mini-card row (Req 10). Up to 3 chart-only
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
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="Pinned reports">
            @foreach ($cards as $card)
                <a
                    href="{{ route('reports.index', ['report' => $card['id']]) }}"
                    class="block rounded-lg border border-slate-200 bg-white p-4 transition-colors hover:border-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-slate-600"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="min-w-0 flex-1 truncate text-base font-semibold text-slate-900 dark:text-slate-100">{{ $card['name'] }}</p>
                        <span class="shrink-0 text-sm text-slate-400 dark:text-slate-500" aria-label="Pinned report" title="Pinned report">📌</span>
                    </div>

                    <div
                        style="width:100%"
                        class="mt-2"
                        x-data="{ chart: null }"
                        x-init="
                            if (! window.ApexCharts) { return; }
                            const opts = window.beatraxApplyChartTheme(JSON.parse($el.dataset.options));
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
                </a>
            @endforeach
        </div>
    @endif
</div>
