{{--
    Inline dashboard card — "Unusual charges" open count + per-detector
    breakdown helper line. Hidden entirely when openCount === 0 so
    the dashboard collapses gracefully on a quiet day.

    The whole tile is wrapped in an <a> linking to /drift?type=anomaly so
    the affordance is the entire card; the inner hover:ring-2 mirrors the
    drift tile chrome.

    Variables in scope:
      - $openCount : int
      - $breakdown : array<string, int> — reason key => open count

    Blade default `{{ }}` escaping for every interpolation.
--}}

@use('Modules\Core\Public\Support\Lang')
@php
    // Build the helper line: "{N} open · {L} large · {F} first-time · {D}
    // duplicate", showing only the non-zero detector counts (UI-SPEC §4).
    $labels = [
        'large' => Lang::get('anomaly::dashboard.detectors.large'),
        'first_time' => Lang::get('anomaly::dashboard.detectors.first_time'),
        'duplicate' => Lang::get('anomaly::dashboard.detectors.duplicate'),
    ];
    $parts = [];
    foreach ($labels as $key => $label) {
        $n = (int) ($breakdown[$key] ?? 0);
        if ($n > 0) {
            $parts[] = $n.' '.$label;
        }
    }
    $helper = $openCount.' '.Lang::get('anomaly::dashboard.open');
    if ($parts !== []) {
        $helper .= ' · '.implode(' · ', $parts);
    }
@endphp

<div>
    @if ($openCount > 0)
        <a
            href="{{ route('drift.index', ['type' => 'anomaly']) }}"
            class="block rounded-lg border border-slate-200 bg-white p-6 transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:hover:ring-slate-700"
            aria-label="{{ Lang::get('anomaly::dashboard.aria_label', ['count' => $openCount]) }}"
        >
            <p class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('anomaly::dashboard.title') }}</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $openCount }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $helper }}</p>
        </a>
    @endif
</div>
