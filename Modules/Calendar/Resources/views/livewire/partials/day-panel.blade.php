@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\Enums\Direction')
{{--
    Day panel partial — §6.4 (desktop right-rail) and §6.5 (phone bottom sheet).

    Included by calendar-page.blade.php in two contexts:
      1. Inside <aside class="cal-day-panel"> for desktop right-rail
      2. Inside <x-bottom-sheet name="day-detail"> for phone

    $dayDto: CalendarDayDto

    Renders: SOD balance, entry rows with series + counterparty drill links,
    approximate note, paid/missed state, EOD balance.
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
{{-- $showDate: the desktop right rail has no heading above this partial, so
     the date belongs here. The phone wraps it in x-core::bottom-sheet, which
     draws its own title — and printed the date TWICE, stacked, in two formats
     ("24 Aug" over "24 Aug 2026"). Read on an iPhone 12 mini. --}}
<div class="cal-panel-header">
    @if ($showDate ?? true)
        <span class="font-semibold" style="font-size: var(--text-md, 1rem); color: var(--color-text);">
            {{ $dayDto->date->translatedFormat('j M Y') }}
        </span>
    @endif
    <x-core::emoji-action
        :label="Lang::get('calendar::messages.panel.close')"
        :caption="Lang::get('calendar::messages.panel.close_caption')"
        class="ml-auto"
        wire:click="$set('selectedDay', null)"
        x-on:click="panelOpen = false"
    >✖️</x-core::emoji-action>
</div>

{{-- SOD balance — "—" when computing OR when no honest SoD exists:
     null sodBalanceMinor means the prior day carried no computed balance;
     rendering €0,00 there would state a fake figure --}}
<div class="cal-panel-bal-row">
    <span style="color: var(--color-text-muted); font-size: var(--text-sm, 0.8125rem);">{{ Lang::get('calendar::messages.panel.start_of_day') }}</span>
    <span class="tabular-nums font-semibold" style="font-size: var(--text-sm, 0.8125rem); color: var(--color-text);">
        @if (! $dayDto->showsBalance() || $dayDto->sodBalanceMinor === null)
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
                $amountSign = $entry->direction === Direction::Income->value ? '+' : '−';
                $amountStr  = $amountSign . Money::ofMinor(abs($entry->amountMinor), $entry->currency)->format();
                $amountColor = $entry->direction === Direction::Income->value ? 'var(--color-emerald)' : 'var(--color-text)';
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
                        {{-- Drill-through links. route(), not a path
                             built by hand: it encodes the slug, which arrived
                             here as stored data. --}}
                        <div class="mt-1 flex flex-wrap gap-2 text-xs" style="color: var(--color-text-muted);">
                            @if ($entry->seriesId !== null)
                                <a
                                    href="{{ route('recurring.series.show', ['seriesId' => $entry->seriesId]) }}"
                                    class="tap-link font-medium underline-offset-2 hover:underline"
                                    style="color: var(--color-text-muted);"
                                    wire:navigate
                                >{{ Lang::get('calendar::messages.panel.series') }}</a>
                            @endif
                            {{-- Not an @elseif: one entry can carry both once a
                                 booked row retires the occurrence a cadence
                                 expected, and the reader is owed the row it was
                                 paid by as well as the series it belongs to. --}}
                            @if ($entry->transactionId !== null)
                                <a
                                    href="{{ route('transactions.show', ['transactionId' => $entry->transactionId]) }}"
                                    class="tap-link font-medium underline-offset-2 hover:underline"
                                    style="color: var(--color-text-muted);"
                                    wire:navigate
                                >{{ Lang::get('calendar::messages.panel.transaction') }}</a>
                            @endif
                            @if ($entry->counterpartySlug !== null)
                                <a
                                    href="{{ route('counterparties.profile', ['slug' => $entry->counterpartySlug]) }}"
                                    class="tap-link font-medium underline-offset-2 hover:underline"
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
    <span class="tabular-nums font-semibold" style="font-size: var(--text-sm, 0.8125rem); color: {{ $dayDto->showsBalance() && $dayDto->eodBalanceMinor < 0 ? 'var(--color-rose)' : 'var(--color-text)' }};">
        @if ($dayDto->showsBalance())
            {{ Money::ofMinor($dayDto->eodBalanceMinor, $dayDto->currency)->format() }}
        @else
            —
        @endif
    </span>
</div>
@if ($dayDto->unconvertedCurrencies !== [])
    <p data-not-converted="true" class="text-xs" style="color: var(--color-text-faint);">
        {{ Lang::get('core::money.not_converted', ['list' => implode(', ', $dayDto->unconvertedCurrencies)]) }}
    </p>
@endif
{{-- The rows above are drawn from every visible account and the two figures
     around them from the balance set alone. Where those disagree the panel
     names the account, or it has printed a start and an end the payments
     between them cannot reach. --}}
@if ($dayDto->uncountedAccounts !== [])
    <p data-not-counted="true" class="text-xs" style="color: var(--color-text-faint);">
        {{ Lang::get('calendar::messages.balance.not_counted', ['list' => implode(', ', $dayDto->uncountedAccounts)]) }}
    </p>
@endif
