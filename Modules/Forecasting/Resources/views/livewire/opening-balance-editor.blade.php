@use('Modules\Core\Public\Support\Lang')
{{--
    Per-account opening-balance editor — inline on /settings.

    Renders the opening balance + opening-balance-as-of fields side by
    side. The soft-warning banner appears below the inputs when the
    Action raises an OpeningBalanceDivergenceWarning; the banner offers
    two chips: "Use Beatrax's number" (replaces the input with the
    computed sum-of-transactions) and "Use my number" (commits the
    user's value with allowDivergence=true).

    Variables in scope:
      - $accountId : int
      - $accountName : string
      - $accountKind : string  ('bank' | 'ics_card' | 'paypal' | 'cash' | ...)
      - $currency : string
      - $openingInput : string  (user-typed)
      - $asOfInput : string     (ISO YYYY-MM-DD)
      - $errorMessage : ?string
      - $showingDivergenceBanner : bool
      - $divergenceDiffMinor : ?int
      - $beatraxsNumberMinor : ?int
      - $saved : bool
--}}

@php
    $symbol = $currency === 'EUR' ? '€' : ($currency === 'USD' ? '$' : $currency);
    $helpText = match (true) {
        str_contains($accountKind, 'paypal') => Lang::get('forecasting::opening_balance.help_paypal'),
        default => Lang::get('forecasting::opening_balance.help_default'),
    };
@endphp

<fieldset class="space-y-3 rounded-md border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
    <legend class="sr-only">{{ Lang::get('forecasting::opening_balance.legend', ['name' => $accountName]) }}</legend>
    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $accountName }}</h3>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="space-y-1">
            <label for="opening-input-{{ $accountId }}" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::opening_balance.opening_label') }}</label>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $helpText }}</p>
            <div class="flex items-center gap-1">
                <span class="text-sm text-slate-500 dark:text-slate-400" aria-hidden="true">{{ $symbol }}</span>
                <input
                    type="text"
                    inputmode="decimal"
                    id="opening-input-{{ $accountId }}"
                    wire:model="openingInput"
                    placeholder="{{ Lang::get('forecasting::opening_balance.opening_placeholder') }}"
                    aria-describedby="opening-help-{{ $accountId }}"
                    class="w-full rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    style="font-variant-numeric: tabular-nums;"
                >
            </div>
        </div>

        <div class="space-y-1">
            <label for="as-of-input-{{ $accountId }}" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::opening_balance.as_of_label') }}</label>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::opening_balance.as_of_help') }}</p>
            <input
                type="date"
                id="as-of-input-{{ $accountId }}"
                wire:model="asOfInput"
                class="w-full rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            >
        </div>
    </div>

    @if ($showingDivergenceBanner)
        <div aria-atomic="true" aria-live="polite" class="rounded-md border border-amber-200 bg-amber-50 p-3 dark:bg-amber-950" data-testid="opening-balance-divergence-banner">
            <p class="text-sm text-amber-700">
                {{ Lang::get('forecasting::opening_balance.divergence') }}
            </p>
            <div class="mt-2 flex flex-wrap gap-3">
                <button
                    type="button"
                    wire:click="useBeatraxsNumber"
                    class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-100"
                >{{ Lang::get('forecasting::opening_balance.use_beatrax') }}</button>
                <button
                    type="button"
                    wire:click="useMyNumber"
                    class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-100"
                >{{ Lang::get('forecasting::opening_balance.use_mine') }}</button>
            </div>
        </div>
    @endif

    @if ($errorMessage !== null)
        <p class="text-sm text-rose-600 dark:text-rose-500" role="alert">{{ $errorMessage }}</p>
    @endif

    <div class="flex items-center gap-2">
        <button
            type="button"
            wire:click="save"
            class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
        >{{ Lang::get('forecasting::opening_balance.save') }}</button>

        @if ($saved)
            <span wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400">{{ Lang::get('forecasting::opening_balance.saved') }}</span>
        @endif
    </div>
</fieldset>
