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
    $symbol = $currency === 'EUR' ? '€' : ($currency === 'USD' ? '$' : $currency);
@endphp

<div role="dialog" aria-labelledby="buffer-editor-heading-{{ $accountId }}" aria-modal="false">
    <h4 id="buffer-editor-heading-{{ $accountId }}" class="text-sm font-medium text-slate-900">
        Set minimum buffer for {{ $accountName }}
    </h4>
    <p id="buffer-help-{{ $accountId }}" class="mt-1 text-xs text-slate-500">
        Alert me when projected balance dips below this amount.
    </p>

    <div class="mt-3 flex items-center gap-1">
        <span class="text-sm text-slate-500" aria-hidden="true">{{ $symbol }}</span>
        <input
            type="text"
            inputmode="decimal"
            pattern="[0-9.,]+"
            wire:model="bufferInput"
            aria-describedby="buffer-help-{{ $accountId }}"
            class="w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            style="font-variant-numeric: tabular-nums;"
        >
    </div>

    @if ($errorMessage !== null)
        <p class="mt-2 text-xs text-rose-600">{{ $errorMessage }}</p>
    @endif

    <div class="mt-3 flex items-center gap-2">
        <button
            type="button"
            wire:click="save"
            class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2"
        >Save buffer</button>
        <button
            type="button"
            x-on:click="open = false"
            class="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-1 text-xs text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Cancel</button>
    </div>

    <div class="mt-2 text-right">
        <button
            type="button"
            wire:click="clear"
            class="text-xs text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Clear buffer (use €0 floor)</button>
    </div>
</div>
