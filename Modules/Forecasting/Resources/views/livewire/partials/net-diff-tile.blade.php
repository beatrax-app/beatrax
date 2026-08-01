{{--
    Net diff tile partial — rendered ABOVE the side-by-side baseline +
    scenario panel pair on the /forecast page when a scenario is
    active.

    Three direction-aware delta numerics at horizon day 30 / 60 / 90:
    positive (scenario is BETTER than baseline) tints emerald-700,
    negative (scenario is WORSE) tints rose-700, zero leaves the
    numeric in default slate-900. The locale-aware Money formatter
    uses the project's nl_NL EUR convention.

    Inputs:
      - $netDiff: array<int, int> keyed by horizon day with signed
        minor-unit deltas (scenario - baseline).
      - $netDiffCurrency: ISO 4217 currency string for the formatter.
      - $horizonDays: list<int> of horizon days to render (the constant
        ProjectForecastJob::HORIZON_DAYS passed in from ForecastPage so
        the template stays in lock step with the canonical horizon set).
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@php
    $fmt = static function (int $absMinor, string $currency = 'EUR'): string {
        $value = $absMinor / Money::MINOR_UNITS_PER_MAJOR;
        $formatter = new \NumberFormatter('nl_NL', \NumberFormatter::CURRENCY);
        $rendered = $formatter->formatCurrency($value, $currency);

        return $rendered === false ? number_format($value, 2, ',', '.') : $rendered;
    };
@endphp

<section class="mb-6 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700" aria-label="Net diff between baseline and scenario at horizon days 30 / 60 / 90">
    <p class="text-sm text-slate-500 dark:text-slate-400">Net diff</p>
    <div class="mt-1 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach (($horizonDays ?? [30, 60, 90]) as $horizonKey)
            @php
                $delta = $netDiff[$horizonKey] ?? 0;
                $sign = $delta > 0 ? '+' : ($delta < 0 ? '−' : '');
                $tint = $delta > 0
                    ? 'text-emerald-700 dark:text-emerald-500'
                    : ($delta < 0 ? 'text-rose-700 dark:text-rose-500' : 'text-slate-900 dark:text-slate-100');
                $aria = $delta > 0 ? 'better than baseline' : ($delta < 0 ? 'worse than baseline' : 'equal to baseline');
                $formatted = $fmt(abs($delta), $netDiffCurrency ?? BaseCurrency::value());
            @endphp
            <div>
                <p
                    class="text-3xl font-semibold {{ $tint }}"
                    style="font-variant-numeric: tabular-nums;"
                    aria-label="Net difference at day {{ $horizonKey }}: {{ $sign }}{{ $formatted }}, scenario is {{ $aria }}"
                >{{ $sign }}{{ $formatted }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">at day {{ $horizonKey }}</p>
            </div>
        @endforeach
    </div>
</section>
