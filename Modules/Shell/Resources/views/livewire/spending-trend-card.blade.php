@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor): string => Money::ofMinor($minor, $trend->currency)->format();
    $signed = static fn (int $minor): string => ($minor >= 0 ? '+' : '−').Money::ofMinor(abs($minor), $trend->currency)->format();
    // Spending more than last period reads as rose (worth noticing); less = emerald.
    $deltaClass = static fn (string $dir): string => match ($dir) {
        'up' => 'text-rose-600 dark:text-rose-400',
        'down' => 'text-emerald-600 dark:text-emerald-400',
        default => 'text-slate-500 dark:text-slate-400',
    };
    $totalDir = $trend->totalDeltaMinor > 0 ? 'up' : ($trend->totalDeltaMinor < 0 ? 'down' : 'flat');
@endphp

<div>
    @if ($trend->hasComparison())
        <x-core::card tag="section" aria-label="{{ Lang::get('core::spending_trend.aria') }}">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::spending_trend.heading') }}</h2>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ Lang::get('core::spending_trend.vs', ['label' => $trend->previousLabel]) }}</span>
            </div>

            <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="text-2xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $fmt($trend->currentTotalMinor) }}</span>
                <span class="text-sm font-medium {{ $deltaClass($totalDir) }}" style="font-variant-numeric: tabular-nums;">{{ $signed($trend->totalDeltaMinor) }}</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ Lang::get('core::spending_trend.spent_this_period') }}</span>
            </div>

            @if (count($trend->movers) > 0)
                <ul class="mt-4 space-y-1.5">
                    @foreach ($trend->movers as $mover)
                        @php $dir = $mover->direction(); @endphp
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">{{ $mover->name }}</span>
                            <span class="shrink-0 text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($mover->currentMinor) }}</span>
                            <span class="w-20 shrink-0 text-right font-medium {{ $deltaClass($dir) }}" style="font-variant-numeric: tabular-nums;">{{ $signed($mover->deltaMinor) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-core::card>
    @endif
</div>
