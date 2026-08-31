@use('Modules\Core\Public\Support\Lang')
{{--
    Model what-if ↗ dropdown — mounted next to the threshold editor
    on the `/recurring/series/{id}` page.

    Trigger: a slate-500 text link. Open: a small dropdown with two
    options — Model cancellation (invokes the launchpad + redirects
    to /forecast?scenarioId={new}); Model amount change… (opens an
    inline amount input + Save / Cancel buttons; on Save, invokes
    the second launchpad + redirects).
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\ValueObjects\MoneyInput')
<div class="relative inline-block">
    @if ($mode === 'closed')
        <button
            type="button"
            wire:click="openMenu"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
        >{{ Lang::get('forecasting::scenario.whatif.trigger') }} ↗</button>
    @elseif ($mode === 'menu')
        <div
            role="menu"
            aria-label="{{ Lang::get('forecasting::scenario.whatif.menu_aria', ['name' => $seriesName]) }}"
            class="absolute right-0 z-10 mt-1 w-56 rounded-md border border-slate-200 bg-white p-2 text-sm shadow-lg dark:bg-slate-950 dark:border-slate-700"
        >
            <button
                type="button"
                role="menuitem"
                wire:click="modelCancellation"
                class="block w-full rounded-md px-2 py-1.5 text-left text-slate-900 hover:bg-slate-50 dark:hover:bg-slate-900 dark:text-slate-100"
            >{{ Lang::get('forecasting::scenario.whatif.model_cancellation') }}</button>
            <button
                type="button"
                role="menuitem"
                wire:click="openAmountForm"
                class="block w-full rounded-md px-2 py-1.5 text-left text-slate-900 hover:bg-slate-50 dark:hover:bg-slate-900 dark:text-slate-100"
            >{{ Lang::get('forecasting::scenario.whatif.model_amount_change') }}</button>
            <div class="mt-1 border-t border-slate-100 pt-1">
                <button
                    type="button"
                    wire:click="closeMenu"
                    class="block w-full rounded-md px-2 py-1.5 text-left text-xs text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-900 dark:text-slate-400"
                >{{ Lang::get('forecasting::scenario.cancel') }}</button>
            </div>
        </div>
    @elseif ($mode === 'amount-form')
        <div
            role="dialog"
            aria-label="{{ Lang::get('forecasting::scenario.whatif.amount_dialog_aria', ['name' => $seriesName]) }}"
            class="absolute right-0 z-10 mt-1 w-72 rounded-md border border-slate-200 bg-white p-3 text-sm shadow-lg dark:bg-slate-950 dark:border-slate-700"
        >
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.whatif.current_amount') }}</p>
            <p class="mb-2 text-sm font-semibold text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                {{ Money::ofMinor(abs($currentAmountMinor), $currency)->format() }}
            </p>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.whatif.new_amount') }}
                <input
                    type="text"
                    wire:model.live="newAmountInput"
                    wire:keydown.enter.prevent="saveAmountChange"
                    inputmode="{{ MoneyInput::decimalPlaces($currency) === 0 ? 'numeric' : 'decimal' }}"
                    placeholder="{{ MoneyInput::formatAbsMinor(1149, $currency) }}"
                    class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700"
                >
            </label>
            @if ($errorMessage !== null)
                <p class="mt-1 text-xs text-rose-700 dark:text-rose-500" role="alert">{{ $errorMessage }}</p>
            @endif
            <div class="mt-3 flex items-center gap-2">
                <button
                    type="button"
                    wire:click="saveAmountChange"
                    class="rounded-md bg-emerald-700 px-3 py-1 text-sm text-white hover:bg-emerald-800 dark:hover:bg-emerald-800 dark:bg-emerald-700"
                >{{ Lang::get('forecasting::scenario.save') }}</button>
                <button
                    type="button"
                    wire:click="closeMenu"
                    class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >{{ Lang::get('forecasting::scenario.cancel') }}</button>
            </div>
        </div>
    @endif
</div>
