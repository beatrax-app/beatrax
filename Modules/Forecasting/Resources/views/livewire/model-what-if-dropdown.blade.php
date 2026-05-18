{{--
    Model what-if ↗ dropdown — mounted next to the threshold editor
    on the `/recurring/series/{id}` page.

    Trigger: a slate-500 text link. Open: a small dropdown with two
    options — Model cancellation (invokes the launchpad + redirects
    to /forecast?scenarioId={new}); Model amount change… (opens an
    inline amount input + Save / Cancel buttons; on Save, invokes
    the second launchpad + redirects).
--}}
<div class="relative inline-block">
    @if ($mode === 'closed')
        <button
            type="button"
            wire:click="openMenu"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Model what-if ↗</button>
    @elseif ($mode === 'menu')
        <div
            role="menu"
            aria-label="Model what-if for {{ $seriesName }}"
            class="absolute right-0 z-10 mt-1 w-56 rounded-md border border-slate-200 bg-white p-2 text-sm shadow-lg"
        >
            <button
                type="button"
                role="menuitem"
                wire:click="modelCancellation"
                class="block w-full rounded-md px-2 py-1.5 text-left text-slate-900 hover:bg-slate-50"
            >Model cancellation</button>
            <button
                type="button"
                role="menuitem"
                wire:click="openAmountForm"
                class="block w-full rounded-md px-2 py-1.5 text-left text-slate-900 hover:bg-slate-50"
            >Model amount change…</button>
            <div class="mt-1 border-t border-slate-100 pt-1">
                <button
                    type="button"
                    wire:click="closeMenu"
                    class="block w-full rounded-md px-2 py-1.5 text-left text-xs text-slate-500 hover:bg-slate-50"
                >Cancel</button>
            </div>
        </div>
    @elseif ($mode === 'amount-form')
        <div
            role="dialog"
            aria-label="Model amount change for {{ $seriesName }}"
            class="absolute right-0 z-10 mt-1 w-72 rounded-md border border-slate-200 bg-white p-3 text-sm shadow-lg"
        >
            <p class="text-xs text-slate-500">Current amount</p>
            <p class="mb-2 text-sm font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                {{ number_format(abs($currentAmountMinor) / 100, 2, ',', '.') }} {{ $currency }}
            </p>
            <label class="block text-xs text-slate-500">New amount
                <input
                    type="text"
                    wire:model.live="newAmountInput"
                    wire:keydown.enter.prevent="saveAmountChange"
                    placeholder="11,49"
                    class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900"
                >
            </label>
            @if ($errorMessage !== null)
                <p class="mt-1 text-xs text-rose-700" role="alert">{{ $errorMessage }}</p>
            @endif
            <div class="mt-3 flex items-center gap-2">
                <button
                    type="button"
                    wire:click="saveAmountChange"
                    class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700"
                >Save</button>
                <button
                    type="button"
                    wire:click="closeMenu"
                    class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200"
                >Cancel</button>
            </div>
        </div>
    @endif
</div>
