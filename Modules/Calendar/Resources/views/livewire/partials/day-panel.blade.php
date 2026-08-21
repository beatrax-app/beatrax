@use('Modules\Core\Public\Support\Lang')
{{--
    Day panel partial — §6.4 (desktop right-rail) and §6.5 (phone bottom sheet).

    Included by calendar-page.blade.php in two contexts:
      1. Inside <aside class="cal-day-panel"> for desktop right-rail
      2. Inside <x-bottom-sheet name="day-detail"> for phone

    $dayDto: CalendarDayDto

    Renders: SOD balance, entry rows with series + counterparty drill links (CAL-03),
    approximate note (D-15), paid/missed state, EOD balance.
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
<div class="cal-panel-header">
    <span class="font-semibold" style="font-size: var(--text-md, 1rem); color: var(--color-text);">
        {{ $dayDto->date->translatedFormat('j M Y') }}
    </span>
    <x-core::emoji-action
        :label="Lang::get('calendar::messages.panel.close')"
        class="ml-auto"
        wire:click="$set('selectedDay', null)"
        x-on:click="panelOpen = false"
    >✖️</x-core::emoji-action>
</div>

{{-- SOD balance — "—" when computing OR when no honest SoD exists (WR-08:
     null sodBalanceMinor means the prior day carried no computed balance;
     rendering €0,00 there would state a fake figure) --}}
<div class="cal-panel-bal-row">
    <span style="color: var(--color-text-muted); font-size: var(--text-sm, 0.8125rem);">{{ Lang::get('calendar::messages.panel.start_of_day') }}</span>
    <span class="tabular-nums font-semibold" style="font-size: var(--text-sm, 0.8125rem); color: var(--color-text);">
        @if ($dayDto->isComputing || $dayDto->sodBalanceMinor === null)
            —
        @else
            {{ Money::ofMinor($dayDto->sodBalanceMinor, $dayDto->currency)->format() }}
        @endif
    </span>
</div>

{{-- Entry rows --}}
@if ($dayDto->entries === [])
    <p class="py-4 text-sm" style="color: var(--color-text-muted);">{{ Lang::get('calendar::messages.panel.no_payments') }}</p>
@else
    <div class="overflow-y-auto flex-1">
        @foreach ($dayDto->entries as $entry)
            @php
                $amountSign = $entry->direction === 'income' ? '+' : '−';
                $amountStr  = $amountSign . Money::ofMinor(abs($entry->amountMinor), $entry->currency)->format();
                $amountColor = $entry->direction === 'income' ? 'var(--color-emerald)' : 'var(--color-text)';
            @endphp
            <div class="cal-panel-entry">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1">
                            <span class="font-semibold" style="font-size: var(--text-sm, 0.8125rem); color: var(--color-text);">
                                @if ($entry->isApproximate)
                                    <span aria-hidden="true" style="color: var(--color-text-faint);">~</span>
                                @endif
                                {{ $entry->name }}
                            </span>
                            @if ($entry->isPaid)
                                <span role="img" style="color: var(--color-emerald);" aria-label="{{ Lang::get('calendar::messages.cell.paid') }}">✓</span>
                            @elseif ($entry->isMissed)
                                <span role="img" style="color: var(--color-amber);" aria-label="{{ Lang::get('calendar::messages.cell.missed') }}">!</span>
                            @endif
                        </div>
                        <div class="mt-0.5 text-xs" style="color: var(--color-text-faint);">
                            {{ $entry->accountName }}
                        </div>
                        @if ($entry->isApproximate)
                            <div class="mt-0.5 text-xs italic" style="color: var(--color-text-faint);">
                                {{ Lang::get('calendar::messages.panel.date_approximate') }}
                            </div>
                        @endif
                        {{-- Drill-through links (CAL-03). route(), not a path
                             built by hand: it encodes the slug, which arrived
                             here as stored data. --}}
                        <div class="mt-1 flex flex-wrap gap-2 text-xs" style="color: var(--color-text-muted);">
                            <a
                                href="{{ route('recurring.series.show', ['seriesId' => $entry->seriesId]) }}"
                                class="font-medium underline-offset-2 hover:underline"
                                style="color: var(--color-text-muted);"
                                wire:navigate
                            >{{ Lang::get('calendar::messages.panel.series') }}</a>
                            @if ($entry->counterpartySlug !== null)
                                <a
                                    href="{{ route('counterparties.profile', ['slug' => $entry->counterpartySlug]) }}"
                                    class="font-medium underline-offset-2 hover:underline"
                                    style="color: var(--color-text-muted);"
                                    wire:navigate
                                >{{ Lang::get('calendar::messages.panel.counterparty') }}</a>
                            @endif
                        </div>
                    </div>
                    <span class="flex-shrink-0 tabular-nums font-semibold" style="font-size: var(--text-sm, 0.8125rem); color: {{ $amountColor }};">
                        {{ $amountStr }}
                    </span>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- EOD balance --}}
<div class="cal-panel-bal-row mt-auto">
    <span style="color: var(--color-text-muted); font-size: var(--text-sm, 0.8125rem);">{{ Lang::get('calendar::messages.panel.end_of_day') }}</span>
    <span class="tabular-nums font-semibold" style="font-size: var(--text-sm, 0.8125rem); color: {{ !$dayDto->isComputing && $dayDto->eodBalanceMinor < 0 ? 'var(--color-rose)' : 'var(--color-text)' }};">
        @if ($dayDto->isComputing)
            —
        @else
            {{ Money::ofMinor($dayDto->eodBalanceMinor, $dayDto->currency)->format() }}
        @endif
    </span>
</div>
