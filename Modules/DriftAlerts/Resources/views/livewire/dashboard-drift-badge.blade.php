@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
{{--
    Inline dashboard card — "Drift alerts" open count + helper-line
    annualized impact roll-up. Hidden entirely when openCount === 0
    so the dashboard collapses gracefully on a quiet day.

    The whole tile is wrapped in an <a> linking to /drift so the
    affordance is the entire card; the inner hover:ring-2 mirrors the
    "Email scan health" tile chrome.

    Blade default `{{ }}` escaping for every interpolation.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    // The headline rolls up the annualized impact in EUR. The SUM is
    // in original-currency minor units; the tile presents the absolute
    // magnitude in EUR so it reads as "potential annualized cost".
    $eurMagnitude = abs((int) $totalAnnualizedImpact);
    $eurFormatted = Money::ofMinor($eurMagnitude, 'EUR')->format();
@endphp

<div>
    @if ($openCount > 0)
        <a
            href="{{ Destination::DriftAlerts->url() }}"
            class="block rounded-lg border border-slate-200 bg-white p-6 transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:hover:ring-slate-700"
            aria-label="{{ Lang::get('drift-alerts::dashboard.aria', ['count' => $openCount, 'amount' => $eurFormatted]) }}"
        >
            <p class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('drift-alerts::dashboard.heading') }}</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $openCount }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                {{ Lang::get('drift-alerts::dashboard.open') }} · ↗ {{ $eurFormatted }} {{ Lang::get('drift-alerts::dashboard.annualized_impact') }}
            </p>
        </a>
    @endif
</div>
