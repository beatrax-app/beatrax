{{--
    Dashboard "Forecast highlights" tile.

    REPLACES the Phase 5 "Next ICS settlement" inline tile (D-1013).
    Strict superset: the next-settlement line is preserved as a meta
    line beneath the lowest-projected-balance line.

    Hidden entirely (renders nothing) when the user has neither a
    lowest-projected balance NOR a next ICS settlement — the
    dashboard grid collapses gracefully on a quiet day.

    Variables in scope:
      - $dto : ForecastHighlightsDto
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmtMinor = static function (?int $minor, string $currency = 'EUR'): string {
        if ($minor === null) {
            return '';
        }
        return Money::ofMinor(abs($minor), $currency)->format('nl_NL');
    };
    $lowestFormatted = $fmtMinor($dto->lowestProjectedBalanceMinor);
    $nextSettlementFormatted = $dto->nextIcsSettlement !== null
        ? Money::ofMinor((int) $dto->nextIcsSettlement->amount->toMinor(), $dto->nextIcsSettlement->amount->currency())->format('nl_NL')
        : '';
    $lowestDate = null;
    if (is_string($dto->lowestProjectedBalanceDate) && $dto->lowestProjectedBalanceDate !== '') {
        try {
            $lowestDate = \Carbon\CarbonImmutable::parse($dto->lowestProjectedBalanceDate);
        } catch (\Throwable $e) {
            $lowestDate = null;
        }
    }
@endphp

<div>
    @if ($dto->lowestProjectedBalanceMinor !== null || $dto->nextIcsSettlement !== null)
        <a
            href="{{ route('forecast.index') }}"
            class="block rounded-lg border border-slate-200 bg-white p-6 transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            aria-label="Forecast highlights{{ $dto->activeShortfallCount > 0 ? '; ' . $dto->activeShortfallCount . ' active shortfall windows in the next 30 days' : '' }}"
        >
            <p class="text-base font-semibold text-slate-900">Forecast highlights</p>

            @if ($dto->activeShortfallCount > 0 && $dto->lowestProjectedBalanceMinor !== null)
                <p class="mt-2 text-3xl font-semibold text-rose-700" style="font-variant-numeric: tabular-nums;">
                    {{ $dto->lowestProjectedAccountName }} dips to {{ $lowestFormatted }}{{ $lowestDate !== null ? ' on ' . $lowestDate->format('d M') : '' }}
                </p>
                <p class="mt-1 text-xs text-slate-500" style="font-variant-numeric: tabular-nums;">
                    {{ $dto->activeShortfallCount === 1 ? '1 active shortfall window' : $dto->activeShortfallCount . ' active shortfall windows' }}
                </p>
            @elseif ($dto->lowestProjectedBalanceMinor !== null)
                <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                    Lowest in 30 days: {{ $lowestFormatted }}{{ $lowestDate !== null ? ' on ' . $lowestDate->format('d M') : '' }} &middot; {{ $dto->lowestProjectedAccountName }}
                </p>
            @endif

            @if ($dto->nextIcsSettlement !== null)
                <p class="mt-1 text-xs text-slate-500" style="font-variant-numeric: tabular-nums;">
                    Next ICS settlement: {{ $nextSettlementFormatted }} on {{ $dto->nextIcsSettlement->dueDate->format('d M') }}
                </p>
            @endif
        </a>
    @endif
</div>
