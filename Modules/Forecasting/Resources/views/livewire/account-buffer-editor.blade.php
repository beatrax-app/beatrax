@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\ValueObjects\MoneyInput')
{{--
    Per-account buffer editor popover.

    Mounts inside the /forecast per-account chart header inside a
    `<div x-data="{ open: false }">` wrapper (the wrapper lives in
    forecast-page.blade.php; this partial renders the popover BODY).

    Save delegates to the SetAccountForecastBuffer Public Action via
    the Livewire `save()` method; Clear delegates to the same action
    with bufferMinor=null.

    Variables in scope:
      - $accountId : int
      - $accountName : string
      - $currency : string
      - $currentBufferMinor : ?int
      - $bufferInput : string (user-typed)
      - $errorMessage : ?string
--}}

@php
    // Money::symbolFor, not a pair of ternaries: this editor knew two of the four
    // codes format() writes, so a GBP account showed "GBP 12.34" here and
    // "£12.34" on the row beside it. Placement stays local — the glyph sits
    // before the input by form convention, which assemble() decides per locale.
    $symbol = Money::symbolFor($currency);
@endphp

<div role="dialog" aria-labelledby="buffer-editor-heading-{{ $accountId }}" aria-modal="false">
    <h4 id="buffer-editor-heading-{{ $accountId }}" class="text-sm font-medium text-slate-900 dark:text-slate-100">
        {{ Lang::get('forecasting::buffer.heading', ['name' => $accountName]) }}
    </h4>
    <p id="buffer-help-{{ $accountId }}" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
        {{ Lang::get('forecasting::buffer.help') }}
    </p>

    <div class="mt-3 flex items-center gap-1">
        <span class="text-sm text-slate-500 dark:text-slate-400" aria-hidden="true">{{ $symbol }}</span>
        <label for="buffer-input-{{ $accountId }}" class="sr-only">{{ Lang::get('forecasting::buffer.input_aria', ['name' => $accountName]) }}</label>
        <input
            id="buffer-input-{{ $accountId }}"
            type="text"
            inputmode="{{ MoneyInput::decimalPlaces($currency) === 0 ? 'numeric' : 'decimal' }}"
            pattern="[0-9.,]+"
            wire:model="bufferInput"
            aria-describedby="buffer-help-{{ $accountId }}"
            class="w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
            style="font-variant-numeric: tabular-nums;"
        >
    </div>

    @if ($errorMessage !== null)
        <p class="mt-2 text-xs text-rose-600 dark:text-rose-500">{{ $errorMessage }}</p>
    @endif

    <div class="mt-3 flex items-center gap-2">
        <button
            type="button"
            wire:click="save"
            class="inline-flex items-center rounded-md bg-emerald-700 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
        >{{ Lang::get('forecasting::buffer.save') }}</button>
        <x-core::secondary-button
            size="sm"
            x-on:click="open = false"
        >{{ Lang::get('forecasting::buffer.cancel') }}</x-core::secondary-button>
    </div>

    <div class="mt-2 text-right">
        <button
            type="button"
            wire:click="clear"
            class="text-xs text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
        >{{ Lang::get('forecasting::buffer.clear', ['zero' => Money::ofMinor(0, $currency)->formatWholeUnits()]) }}</button>
    </div>
</div>
