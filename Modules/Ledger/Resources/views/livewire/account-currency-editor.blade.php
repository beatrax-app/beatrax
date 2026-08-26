@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\ValueObjects\Money')
{{--
    Per-account currency picker — inline on /settings, beside the
    opening-balance editor that is denominated in whatever this names.

    Choosing a code and saving relabels the account. The warning banner
    appears when the Action raises an AccountCurrencyRelabelWarning, which it
    does for any account that already holds a baseline or a transaction; it
    offers "Change anyway" (commits with allowRelabel=true) and "Keep <code>"
    (puts the select back, writes nothing).

    Variables in scope:
      - $accountId : int
      - $accountName : string
      - $currency : string        (bound to the select)
      - $storedCurrency : string  (what the row holds)
      - $currencyOptions : array<string, string>  (code => name)
      - $errorMessage : ?string
      - $showingRelabelBanner : bool
      - $relabelBaselineMinor : ?int
      - $relabelLines : array<string, int>
      - $saved : bool
--}}

<fieldset class="space-y-3 rounded-md border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
    <legend class="sr-only">{{ Lang::get('ledger::account_currency.legend', ['name' => $accountName]) }}</legend>
    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $accountName }}</h3>

    <x-core::form-field
        name="currency"
        type="select"
        field-id="account-currency-{{ $accountId }}"
        :label="Lang::get('ledger::account_currency.label')"
        :hint="Lang::get('ledger::account_currency.help')"
        wire:model="currency"
        class="max-w-xs"
        data-testid="account-currency-select-{{ $accountId }}"
    >
        @foreach ($currencyOptions as $code => $currencyName)
            <option value="{{ $code }}">{{ $code }} — {{ $currencyName }}</option>
        @endforeach
    </x-core::form-field>

    @if ($showingRelabelBanner)
        <x-core::alert tone="warning" aria-atomic="true" aria-live="polite" data-testid="account-currency-relabel-banner">
            <p>{{ Lang::get('ledger::account_currency.warning.intro', ['from' => $storedCurrency, 'to' => $currency]) }}</p>

            @if ($relabelBaselineMinor !== null)
                <p class="mt-2">
                    {{ Lang::get('ledger::account_currency.warning.baseline', [
                        'amount' => Money::ofMinor($relabelBaselineMinor, $storedCurrency)->format(),
                        'to' => $currency,
                    ]) }}
                </p>
            @endif

            @if ($relabelLines !== [])
                <p class="mt-2">{{ Lang::get('ledger::account_currency.warning.lines') }}</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($relabelLines as $lineCurrency => $lineMinor)
                        <li style="font-variant-numeric: tabular-nums;">{{ Money::ofMinor($lineMinor, $lineCurrency)->format() }}</li>
                    @endforeach
                </ul>
                <p class="mt-2">{{ Lang::get('ledger::account_currency.warning.reads', ['to' => $currency]) }}</p>
            @endif

            <div class="mt-2 flex flex-wrap gap-3">
                <button
                    type="button"
                    wire:click="relabelAnyway"
                    class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-100"
                >{{ Lang::get('ledger::account_currency.warning.confirm') }}</button>
                <button
                    type="button"
                    wire:click="keepCurrent"
                    class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-100"
                >{{ Lang::get('ledger::account_currency.warning.keep', ['currency' => $storedCurrency]) }}</button>
            </div>
        </x-core::alert>
    @endif

    @if ($errorMessage !== null)
        <p class="text-sm text-rose-600 dark:text-rose-500" role="alert">{{ $errorMessage }}</p>
    @endif

    <div class="flex items-center gap-2">
        <button
            type="button"
            wire:click="save"
            class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
        >{{ Lang::get('ledger::account_currency.save') }}</button>

        @if ($saved)
            <span wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400">{{ Lang::get('ledger::account_currency.saved') }}</span>
        @endif
    </div>
</fieldset>
