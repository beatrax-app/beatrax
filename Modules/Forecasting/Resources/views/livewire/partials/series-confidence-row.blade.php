{{--
    One row in the confidence legend sidebar on /forecast.

    Renders per series: a name, a tabular-aligned monthly-equivalent
    line, and a chip whose tint reflects the bucket.

    Variables in scope:
      - $confidence : Modules\Forecasting\Public\Dto\SeriesConfidenceDto
--}}

@php
    $tint = match ($confidence->confidence) {
        'high' => 'bg-emerald-50 text-emerald-700',
        'low' => 'bg-amber-50 text-amber-700',
        default => 'bg-slate-100 text-slate-700',
    };
    $symbol = $confidence->currency === 'EUR' ? '€' : ($confidence->currency === 'USD' ? '$' : $confidence->currency.' ');
    $point = abs($confidence->pointMinor) / 100;
    $formattedPoint = $symbol.number_format($point, 2, ',', '.');
    $rangeWidthPercent = $confidence->pointMinor !== 0
        ? round((float) $confidence->bandWidthMinor / abs($confidence->pointMinor) * 100, 0)
        : 0;
@endphp

<li class="flex items-center justify-between gap-3 py-1 text-sm">
    <div class="min-w-0 flex-1">
        <p class="truncate text-slate-900">{{ $confidence->seriesName }}</p>
        <p class="text-xs text-slate-500" style="font-variant-numeric: tabular-nums;">{{ $formattedPoint }}/mo</p>
    </div>
    <span
        class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tint }}"
        aria-label="{{ $confidence->seriesName }}, {{ $confidence->confidence }} confidence — projection range is {{ $rangeWidthPercent }} percent of the point estimate"
    >{{ $confidence->confidence }}</span>
</li>
