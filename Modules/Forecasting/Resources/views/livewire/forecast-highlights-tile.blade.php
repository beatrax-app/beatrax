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
        } catch (\Throwable) {
            $lowestDate = null;
        }
    }
@endphp

<div>
    @if ($dto->lowestProjectedBalanceMinor !== null || $dto->nextIcsSettlement !== null)
        {{-- The card chrome is the wrapper's and the affordance is still the
             whole text block, but the disclosure's trigger is a button and a
             button is not allowed inside a link — so the tile is a box holding
             a link, not a link painted as a box. --}}
        <div class="rounded-lg border border-slate-200 bg-white p-6 transition hover:ring-2 hover:ring-slate-200 dark:bg-slate-950 dark:border-slate-700">
            <a
                href="{{ Destination::Forecasts->url() }}"
                class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                aria-label="{{ Lang::get('forecasting::forecast.highlights_title') }}{{ ! $dto->isComputing && $dto->activeShortfallCount > 0 ? '; ' . Lang::choice('forecasting::forecast.highlights_shortfall_aria', $dto->activeShortfallCount, ['count' => $dto->activeShortfallCount, 'days' => ForecastHighlightsQuery::TILE_HORIZON]) : '' }}"
            >
                <p class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::forecast.highlights_title') }}</p>

                @if ($dto->isComputing)
                    {{-- The figure, its date, its account and the shortfall count
                         all come out of the run this one supersedes. The chart on
                         the forecast page already says "Updating" here; this tile
                         printed the old numbers instead and read as current.
                         The poll element is conditional so it unmounts itself the
                         moment the run lands, as the chart's does. --}}
                    <p wire:poll.2s.keep-alive="$refresh" class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::forecast.updating') }}&hellip;</p>
                @elseif ($dto->lowestProjectedBalanceMinor !== null)
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
                    {{-- A date already past carries its year: "19 May" alone reads
                         as this year's, and an unsettled statement can be older. --}}
                    <p class="mt-1 text-xs {{ $dto->icsSettlementOverdue ? 'text-amber-700 dark:text-amber-500' : 'text-slate-500 dark:text-slate-400' }}" style="font-variant-numeric: tabular-nums;">
                        @if ($dto->icsSettlementOverdue)
                            {{ Lang::get('forecasting::forecast.ics_overdue', ['amount' => $nextSettlementFormatted, 'date' => $dto->nextIcsSettlement->dueDate->translatedFormat('d M Y')]) }}
                        @else
                            {{ Lang::get('forecasting::forecast.next_ics', ['amount' => $nextSettlementFormatted, 'date' => $dto->nextIcsSettlement->dueDate->translatedFormat('d M')]) }}
                        @endif
                    </p>
                @endif
            </a>
            {{-- One account's dip is named above, and which account that is was
                 decided in the reader's own currency: a yen minor unit is not a
                 euro cent. An account no rate reaches never enters that race,
                 so the figure shown is the lowest of a set the reader could not
                 otherwise know was short one. --}}
            <x-core::fx-disclosure
                :disclosure="$dto->conversion"
                id="forecast-highlights-lowest"
                :label="Lang::get('forecasting::forecast.lowest_in_30_label')"
                class="mt-1 block text-xs text-slate-500 dark:text-slate-400"
            />
        </div>
    @endif
</div>
