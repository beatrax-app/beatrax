@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Forecasting\Public\Services\ForecastHighlightsQuery')
@use('Modules\Ledger\Public\Services\BaseCurrency')
{{--
    Dashboard "Forecast highlights" tile.

    Replaces the earlier "Next ICS settlement" inline tile as a strict
    superset: the next-settlement line is preserved as a meta line
    beneath the lowest-projected-balance line.

    Shape: title, then the lowest projected figure alone at display size,
    then the words that qualify it (label, date, account) as meta lines —
    rose throughout when a shortfall window is active.

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
    $fmtMinor = static function (?int $minor, ?string $currency = null): string {
        if ($minor === null) {
            return '';
        }
        return Money::ofMinor($minor, $currency ?? BaseCurrency::value())->format();
    };
    $lowestFormatted = $fmtMinor($dto->lowestProjectedBalanceMinor, $dto->lowestProjectedBalanceCurrency);
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

            @if ($dto->lowestProjectedBalanceMinor !== null)
                {{-- Figure alone, the shape the net-worth card beside it uses.
                     The whole sentence at this size wrapped to five lines and
                     200px on a 375pt phone, one word of the label per line. --}}
                <p class="mt-2 text-3xl font-semibold @if ($dto->activeShortfallCount > 0) text-rose-700 dark:text-rose-500 @else text-slate-900 dark:text-slate-100 @endif" style="font-variant-numeric: tabular-nums;">{{ $lowestFormatted }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                    {{ Lang::get('forecasting::forecast.lowest_in_30_label') }}{{ $lowestDate !== null ? Lang::get('forecasting::forecast.on_date_suffix', ['date' => $lowestDate->translatedFormat('d M')]) : '' }} &middot; {{ $dto->lowestProjectedAccountName }}
                </p>
                @if ($dto->activeShortfallCount > 0)
                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-500" style="font-variant-numeric: tabular-nums;">
                        {{ Lang::choice('forecasting::forecast.shortfall_window', $dto->activeShortfallCount, ['count' => $dto->activeShortfallCount]) }}
                    </p>
                @endif
            @endif

            @if ($dto->nextIcsSettlement !== null)
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                    {{ Lang::get('forecasting::forecast.next_ics', ['amount' => $nextSettlementFormatted, 'date' => $dto->nextIcsSettlement->dueDate->translatedFormat('d M')]) }}
                </p>
            @endif
        </a>
    @endif
</div>
