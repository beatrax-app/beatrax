@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Forecasting\Public\Services\ForecastHighlightsQuery')
{{--
    Dashboard "Forecast highlights" tile.

    Replaces the earlier "Next ICS settlement" inline tile as a strict
    superset: the next-settlement line is preserved as a meta line
    beneath the lowest-projected-balance line.

    Hidden entirely (renders nothing) when the user has neither a
    lowest-projected balance NOR a next ICS settlement — the
    dashboard grid collapses gracefully on a quiet day.

    Variables in scope:
      - $dto : ForecastHighlightsDto
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    // Preserve the sign so a projected dip BELOW zero (overdraft) renders
    // with the minus sign per nl_NL locale convention. Surfacing the sign
    // is the whole point of the "lowest projected balance" tile — without
    // it the user cannot distinguish "the account is at €100 and dips no
    // further" from "the account hits −€100".
    $fmtMinor = static function (?int $minor, string $currency = 'EUR'): string {
        if ($minor === null) {
            return '';
        }
        return Money::ofMinor($minor, $currency)->format();
    };
    $lowestFormatted = $fmtMinor($dto->lowestProjectedBalanceMinor);
    $nextSettlementFormatted = $dto->nextIcsSettlement !== null
        ? Money::ofMinor((int) $dto->nextIcsSettlement->amount->toMinor(), $dto->nextIcsSettlement->amount->currency())->format()
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
            href="{{ Destination::Forecasts->url() }}"
            class="block rounded-lg border border-slate-200 bg-white p-6 transition hover:ring-2 hover:ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700"
            aria-label="{{ Lang::get('forecasting::forecast.highlights_title') }}{{ $dto->activeShortfallCount > 0 ? '; ' . Lang::choice('forecasting::forecast.highlights_shortfall_aria', $dto->activeShortfallCount, ['count' => $dto->activeShortfallCount, 'days' => ForecastHighlightsQuery::HORIZON_DAYS]) : '' }}"
        >
            <p class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::forecast.highlights_title') }}</p>

            @if ($dto->activeShortfallCount > 0 && $dto->lowestProjectedBalanceMinor !== null)
                <p class="mt-2 text-3xl font-semibold text-rose-700 dark:text-rose-500" style="font-variant-numeric: tabular-nums;">
                    {{ Lang::get('forecasting::forecast.dips_to', ['name' => $dto->lowestProjectedAccountName, 'amount' => $lowestFormatted]) }}{{ $lowestDate !== null ? Lang::get('forecasting::forecast.on_date_suffix', ['date' => $lowestDate->translatedFormat('d M')]) : '' }}
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                    {{ Lang::choice('forecasting::forecast.shortfall_window', $dto->activeShortfallCount, ['count' => $dto->activeShortfallCount]) }}
                </p>
            @elseif ($dto->lowestProjectedBalanceMinor !== null)
                <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                    {{ Lang::get('forecasting::forecast.lowest_in_30', ['amount' => $lowestFormatted]) }}{{ $lowestDate !== null ? Lang::get('forecasting::forecast.on_date_suffix', ['date' => $lowestDate->translatedFormat('d M')]) : '' }} &middot; {{ $dto->lowestProjectedAccountName }}
                </p>
            @endif

            @if ($dto->nextIcsSettlement !== null)
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                    {{ Lang::get('forecasting::forecast.next_ics', ['amount' => $nextSettlementFormatted, 'date' => $dto->nextIcsSettlement->dueDate->translatedFormat('d M')]) }}
                </p>
            @endif
        </a>
    @endif
</div>
