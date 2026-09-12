@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\DriftAlerts\Internal\Enums\AnnualImpactTrend')
{{--
    Inline dashboard card — "Drift alerts" open count + helper-line
    annualized impact roll-up. Hidden entirely when openCount === 0
    so the dashboard collapses gracefully on a quiet day.

    The text block is one <a> to /drift, so the affordance is the whole
    of what the tile says; the hover:ring-2 on the box around it mirrors
    the "Email scan health" tile chrome.

    Blade default `{{ }}` escaping for every interpolation.
--}}

<div>
    @if ($openCount > 0)
        {{-- The card chrome is the wrapper's and the affordance is still the
             whole text block, but the disclosure's trigger is a button and a
             button is not allowed inside a link — so the tile is a box holding
             a link, not a link painted as a box. --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6 transition hover:ring-2 hover:ring-slate-200 dark:bg-slate-950 dark:border-slate-700 dark:hover:ring-slate-700">
            <a
                href="{{ Destination::DriftAlerts->url() }}"
                class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                aria-label="{{ Lang::get('drift-alerts::dashboard.aria', ['count' => $openCount, 'impact' => Lang::get($impactTrend->impactKey(), ['amount' => $totalFormatted])]) }}"
            >
                <p class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('drift-alerts::dashboard.heading') }}</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $openCount }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                    {{ Lang::get('drift-alerts::dashboard.open') }} ·
                    @if ($impactTrend === AnnualImpactTrend::Rising)
                        <span aria-hidden="true">{{ $impactTrend->glyph() }}</span>
                    @endif
                    {{ Lang::get($impactTrend->impactKey(), ['amount' => $totalFormatted]) }}
                </p>
            </a>
            <x-core::fx-disclosure
                :disclosure="$conversion"
                id="drift-badge-impact"
                :label="Lang::get('drift-alerts::dashboard.heading')"
                class="mt-1 block text-xs text-slate-500 dark:text-slate-400"
            />
        </div>
    @endif
</div>
