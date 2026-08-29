{{--
    One row in the confidence legend sidebar on /forecast.

    Renders per series: a name, a tabular-aligned monthly-equivalent
    line, and a chip whose tint reflects the bucket.

    Variables in scope:
      - $confidence : Modules\Forecasting\Public\Dto\SeriesConfidenceDto
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')

@use('Modules\Core\Public\Support\Fmt')
@use('Modules\Core\Public\Support\Lang')
@php
    $tint = match ($confidence->confidence) {
        'high' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
        'low' => 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-200',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    };
    $formattedPoint = Money::ofMinor(abs($confidence->pointMinor), $confidence->currency)->format();
    $rangeWidthPercent = $confidence->pointMinor !== 0
        ? round((float) $confidence->bandWidthMinor / abs($confidence->pointMinor) * 100, 0)
        : 0;
@endphp

<li class="flex items-center justify-between gap-3 py-1 text-sm">
    <div class="min-w-0 flex-1">
        <p class="truncate text-slate-900 dark:text-slate-100">{{ $confidence->seriesName }}</p>
        <p class="text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $formattedPoint }}{{ Lang::get('forecasting::forecast.per_month_suffix') }}</p>
    </div>
    <span
        role="img"
        class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tint }}"
        aria-label="{{ Lang::get('forecasting::forecast.confidence_chip_aria', ['name' => $confidence->seriesName, 'confidence' => $confidence->confidence, 'percent' => Fmt::number($rangeWidthPercent)]) }}"
    >{{ $confidence->confidence }}</span>
</li>
