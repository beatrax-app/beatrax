@use('Modules\Core\Public\Support\Lang')
@use('Modules\Forecasting\Public\Actions\SetAccountOpeningBalance')
@use('Modules\Ledger\Public\Enums\AccountKind')
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\ValueObjects\MoneyInput')
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
      - $currentOpeningMinor : ?int  (null once no override is stored)
      - $openingInput : string  (user-typed)
      - $asOfInput : string     (ISO YYYY-MM-DD)
      - $errorMessage : ?string
      - $showingDivergenceBanner : bool
      - $divergenceDiffMinor : ?int
      - $beatraxsNumberMinor : ?int
      - $saved : bool
--}}

@php
    // Money::symbolFor, not a pair of ternaries: this editor knew two of the four
    // codes format() writes, so a GBP account showed "GBP 12.34" here and
    // "£12.34" on the row beside it. Placement stays local — the glyph sits
    // before the input by form convention, which assemble() decides per locale.
    $symbol = Money::symbolFor($currency);

    // One "1.250,00" served all 26 locales, so an English reader was shown a
    // Dutch figure and a yen account an amount its own parser refuses. Written
    // at the account's scale, with the reader's own marks.
    $example = MoneyInput::formatAbsMinor(125_000, $currency);
    $helpText = match (true) {
        str_contains($accountKind, AccountKind::Paypal->value) => Lang::get('forecasting::opening_balance.help_paypal'),
        default => Lang::get('forecasting::opening_balance.help_default'),
    };
@endphp

<fieldset class="space-y-3 rounded-md border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
    <legend class="sr-only">{{ Lang::get('forecasting::opening_balance.legend', ['name' => $accountName]) }}</legend>
    <x-core::section-heading :title="$accountName" :level="3" />

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="space-y-1">
            <label for="opening-input-{{ $accountId }}" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::opening_balance.opening_label') }}</label>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $helpText }}</p>
            <div class="flex items-center gap-1">
                <span class="text-sm text-slate-500 dark:text-slate-400" aria-hidden="true">{{ $symbol }}</span>
                <input
                    type="text"
                    inputmode="{{ MoneyInput::decimalPlaces($currency) === 0 ? 'numeric' : 'decimal' }}"
                    id="opening-input-{{ $accountId }}"
                    wire:model="openingInput"
                    placeholder="{{ Lang::get('forecasting::opening_balance.opening_placeholder', ['amount' => $example]) }}"
                    aria-describedby="opening-help-{{ $accountId }}"
                    class="w-full rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    style="font-variant-numeric: tabular-nums;"
                >
            </div>
        </div>

        <div class="space-y-1">
            <label for="as-of-input-{{ $accountId }}" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::opening_balance.as_of_label') }}</label>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::opening_balance.as_of_help') }}</p>
            <x-core::date-input
                field-id="as-of-input-{{ $accountId }}"
                wire:model="asOfInput"
                :aria-label="Lang::get('forecasting::opening_balance.as_of_label')"
            />
        </div>
    </div>

    @if ($showingDivergenceBanner)
        {{-- The sentence carries no colour of its own any more: the shared
             alert already paints the warning tone, and its dark: variant is
             the one the hand-rolled copy was missing. --}}
        <x-core::alert tone="warning" aria-atomic="true" aria-live="polite" data-testid="opening-balance-divergence-banner">
            <p>
                {{ Lang::get('forecasting::opening_balance.divergence', ['threshold' => Money::ofMinor(SetAccountOpeningBalance::DIVERGENCE_WARNING_THRESHOLD_MINOR, $currency)->formatWholeUnits()]) }}
                @if ($beatraxsNumberMinor !== null)
                    {{-- The button below overwrites the box with this figure,
                         and the reader was asked to accept it unseen. --}}
                    <span data-testid="opening-balance-computed">{{ Lang::get('forecasting::opening_balance.computed_is', ['amount' => Money::ofMinor($beatraxsNumberMinor, $currency)->format()]) }}</span>
                @endif
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
        </x-core::alert>
    @endif

    @if ($errorMessage !== null)
        <p class="text-sm text-rose-600 dark:text-rose-500" role="alert">{{ $errorMessage }}</p>
    @endif

    <div class="flex items-center gap-2">
        <button
            type="button"
            wire:click="save"
            class="inline-flex items-center rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
        >{{ Lang::get('forecasting::opening_balance.save') }}</button>

        {{-- Only while there is something to take back. The same removal is
             what saving an empty box does; this is the affordance that says
             so, and this figure outranks the import-detected baseline, so a
             mistyped one must not be permanent. --}}
        @if ($currentOpeningMinor !== null)
            <x-core::secondary-button size="sm" wire:click="remove">{{ Lang::get('forecasting::opening_balance.remove') }}</x-core::secondary-button>
        @endif

        @if ($saved)
            <span wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400">{{ $currentOpeningMinor === null ? Lang::get('forecasting::opening_balance.removed') : Lang::get('forecasting::opening_balance.saved') }}</span>
        @endif
    </div>
</fieldset>
