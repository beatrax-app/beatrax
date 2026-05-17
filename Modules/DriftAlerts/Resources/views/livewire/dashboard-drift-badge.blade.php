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

    // The headline rolls up the annualized impact in EUR. UI-SPEC § 5
    // documents the EUR-rollup decision when alerts span multiple
    // currencies. In Wave 3 the SUM is in original-currency minor
    // units; an EUR FX join lands in the follow-up plan. For the
    // dashboard tile we present the absolute magnitude prefixed with
    // an upward arrow so it reads as "potential annualized cost".
    $eurMagnitude = abs((int) $totalAnnualizedImpact);
    $eurFormatted = Money::ofMinor($eurMagnitude, 'EUR')->format('nl_NL');
@endphp

<div>
    @if ($openCount > 0)
        <a
            href="{{ route('drift.index') }}"
            class="block rounded-lg border border-slate-200 bg-white p-6 transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            aria-label="Drift alerts — {{ $openCount }} open, {{ $eurFormatted }} annualized impact"
        >
            <p class="text-base font-semibold text-slate-900">Drift alerts</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">{{ $openCount }}</p>
            <p class="mt-1 text-xs text-slate-500" style="font-variant-numeric: tabular-nums;">
                {{ $openCount === 1 ? 'open' : 'open' }} · ↗ {{ $eurFormatted }} annualized impact
            </p>
        </a>
    @endif
</div>
