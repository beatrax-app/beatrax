@use('Modules\Core\Models\User')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Core\Public\Support\LegalLinks')
@use('Modules\Core\Public\Enums\Theme')
@use('Illuminate\Support\Facades\URL')
@use('Modules\Ledger\Public\Enums\CurrencyView')
@use('Modules\Recurring\Public\Support\RecurringDetectionWindow')
@php
    // Shared card chrome for the grouped settings sections. The redesign only
    // changes the visual container — every control, id, wire: binding, @error
    // block and copy string below is preserved verbatim from the flat layout.
    $card = 'rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950';
    $cardHead = 'text-xs uppercase tracking-wide text-[var(--color-text-faint)]';
    // Group heading: the page is ~20 unrelated sections, and a flat stack of
    // them gives no sense of where anything lives. The existing order already
    // clusters, so these only name the clusters — nothing moves.
    $groupHead = 'pt-4 text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100';
@endphp

{{--
    Phone responsive pass (UI-SPEC §19).
    At <768px: max-width constraint is removed; .settings-grid collapses to a
    single column. Desktop multi-column unchanged.
    The .settings-grid responsive CSS rule lives in resources/css/app.css,
    in the per-page responsive section.
--}}

<div class="max-w-2xl mx-auto space-y-6" data-testid="settings-page">
    <header class="space-y-1">
        <x-core::page-heading level="section">{{ Lang::get('core::settings.title') }}</x-core::page-heading>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.subtitle') }}</p>
    </header>

    <h2 class="{{ $groupHead }}">{{ Lang::get('core::settings.groups.display') }}</h2>

    {{-- ===== Appearance ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.appearance.heading') }}</h2>
            <div class="space-y-1">
                <span class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.appearance.theme') }}</span>
                <div role="radiogroup" aria-label="{{ Lang::get('core::settings.appearance.theme') }}" class="inline-flex rounded-md border border-slate-200 dark:border-slate-700 overflow-hidden">
                    @foreach (Theme::cases() as $themeOption)
                        @php($value = $themeOption->value)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $theme === $value ? 'true' : 'false' }}"
                            wire:click="setTheme('{{ $value }}')"
                            @class([
                                'px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100',
                                'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' => $theme === $value,
                                'bg-white text-slate-900 hover:bg-slate-50 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-900' => $theme !== $value,
                            ])
                        >
                            {{ Lang::get('core::settings.appearance.theme_'.$value) }}
                        </button>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.appearance.theme_help') }}</p>
                @error('theme')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>
    </div>

    {{-- ===== Language ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.language.heading') }}</h2>
            <div class="space-y-1">
                <label for="settings-locale-select" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.language.label') }}</label>
                {{-- A select, not a button per language: the segmented row only
                     works while there are two or three, and the list is meant
                     to grow. Auto is last here and in the theme switcher.

                     The 26 options and the System sentinel are the guest
                     switcher's, drawn by the same component. What this screen
                     supplies is which one opens selected: the STORED
                     preference, which outranks the session key the pre-auth
                     shells read. --}}
                <x-core::locale-select
                    labelled
                    field-id="settings-locale-select"
                    :selected="$locale"
                    select-class="block w-full max-w-xs rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                    wire:change="setLocale($event.target.value)"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.language.help') }}</p>
                @error('locale')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>
    </div>

    {{-- ===== Country ===== --}}
    {{-- Beside the language, and deliberately without flags: a flag names a
         country, so putting one on the language picker is the confusion this
         pair has to avoid. Each help line says what the other one does not do. --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="country">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.country.heading') }}</h2>
            <div class="space-y-1">
                <x-core::form-field
                    type="select"
                    name="country"
                    field-id="settings-country-select"
                    :label="Lang::get('core::settings.country.label')"
                    :hint="Lang::get('core::settings.country.help')"
                    class="max-w-xs"
                    wire:change="setCountry($event.target.value)"
                    data-testid="settings-country-select"
                >
                    {{-- Disabled rather than merely ignored — setCountry()
                         returns early on the empty value, so re-choosing the
                         placeholder was a round-trip that changed nothing and
                         said nothing. This is the one of the four country
                         pickers that cannot go back to unset. --}}
                    <x-core::country-options
                        :options="$countryOptions"
                        :selected="$country"
                        placeholder-disabled
                    />
                </x-core::form-field>
                @if ($country !== '')
                    {{-- The one thing the country does bring in the country's
                         own language, said where the help line above would
                         otherwise be read as ruling it out. --}}
                    <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="settings-country-wording-note">{{ Lang::get('core::settings.country.wording_note', ['country' => $countryOptions[$country] ?? $country]) }}</p>
                    <p class="text-xs text-[var(--color-amber)]">{{ Lang::get('core::settings.country.switch_note') }}</p>
                @endif
                @error('country')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>
    </div>

    <h2 class="{{ $groupHead }}">{{ Lang::get('core::settings.groups.money') }}</h2>

    {{-- ===== Preferences (batch save) ===== --}}
    <form wire:submit="save" class="{{ $card }} space-y-8">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.currency_display.heading') }}</h2>
            <x-core::form-field
                name="defaultCurrencyView"
                type="select"
                :label="Lang::get('core::settings.currency_display.label')"
                :hint="Lang::get('core::settings.currency_display.help')"
                wire:model="defaultCurrencyView"
                class="max-w-xs"
            >
                <option value="{{ CurrencyView::BaseOnly->value }}">{{ Lang::get('core::settings.currency_display.eur_only') }}</option>
                <option value="{{ CurrencyView::Original->value }}">{{ Lang::get('core::settings.currency_display.original') }}</option>
            </x-core::form-field>
        </section>

        {{-- ===== Currency reporting (FX base currency + online fetch toggle) ===== --}}
        <section class="space-y-6">
            {{-- Sub-section A: Base reporting currency picker --}}
            <div class="space-y-2">
                <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.base_currency.heading') }}</h2>
                <x-core::form-field
                    name="baseCurrency"
                    type="select"
                    :label="Lang::get('core::settings.base_currency.label')"
                    :hint="Lang::get('core::settings.base_currency.help')"
                    wire:model="baseCurrency"
                    class="max-w-xs"
                >
                    @foreach ($currencyOptions as $code => $currencyName)
                        <option value="{{ $code }}">{{ $code }} — {{ $currencyName }}</option>
                    @endforeach
                </x-core::form-field>
            </div>

            {{-- Sub-section B: Online exchange-rate fetch toggle --}}
            <div class="space-y-3">
                <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.exchange_rates.heading') }}</h2>
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <p class="text-sm text-[var(--color-text)]">{{ Lang::get('core::settings.exchange_rates.fetch_online') }}</p>
                        <p class="mt-1 text-xs text-[var(--color-text-muted)]">
                            @if ($fxOnlineEnabled)
                                {{ Lang::get('core::settings.exchange_rates.online_on') }}
                                @if ($fxLastUpdated)
                                    {{ Lang::get('core::settings.exchange_rates.last_updated', ['date' => $fxLastUpdated]) }}
                                @endif
                            @else
                                {{ Lang::get('core::settings.exchange_rates.online_off') }}
                            @endif
                        </p>
                    </div>
                    <x-core::switch
                        :on="$fxOnlineEnabled"
                        :label="Lang::get('core::settings.exchange_rates.fetch_aria')"
                        wire:click="toggleFxOnline"
                    />
                </div>

                @if ($fxOnlineEnabled)
                    <div
                        wire:transition
                        class="flex items-center justify-between gap-3"
                        @if ($fxRefreshing) wire:poll.2s.keep-alive="pollFxRefresh" @endif
                    >
                        {{-- The fetch is a queued job that can fail on every
                             provider, so "Refreshing…" needs an end: either the
                             rate table takes a write, or the wait runs out and
                             the line says so. --}}
                        <p class="text-xs" @if ($fxRefreshGaveUp) role="alert" @endif style="color: var(--color-text-faint);">
                            @if ($fxRefreshing)
                                {{ Lang::get('core::settings.exchange_rates.refreshing') }}
                            @elseif ($fxRefreshGaveUp)
                                {{ Lang::get('core::settings.exchange_rates.refresh_gave_up') }}
                            @else
                                {{ Lang::get('core::settings.exchange_rates.next_refresh') }}
                            @endif
                        </p>
                        <x-core::secondary-button
                            size="sm"
                            class="disabled:opacity-50"
                            wire:click="refreshFxRates"
                            wire:loading.attr="disabled"
                        >{{ Lang::get('core::settings.exchange_rates.refresh_now') }}</x-core::secondary-button>
                    </div>
                @endif
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.period.heading') }}</h2>
            <x-core::form-field
                name="periodStartDay"
                type="number"
                min="1"
                max="28"
                :label="Lang::get('core::settings.period.label')"
                :hint="Lang::get('core::settings.period.help')"
                wire:model="periodStartDay"
                class="max-w-xs"
            />
        </section>

        <section class="space-y-4">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.recurring.heading') }}</h2>
            <x-core::form-field
                name="recurringDetectionWindowMonths"
                type="number"
                :min="RecurringDetectionWindow::MINIMUM_MONTHS"
                :max="RecurringDetectionWindow::MAXIMUM_MONTHS"
                :label="Lang::get('core::settings.recurring.window_label')"
                :hint="Lang::get('core::settings.recurring.window_help')"
                wire:model="recurringDetectionWindowMonths"
                class="max-w-xs"
            />
            <x-core::form-field
                name="recurringIncomeMinAmountMinor"
                type="number"
                min="0"
                max="100000000"
                :label="Lang::get('core::settings.recurring.income_label')"
                :hint="Lang::get('core::settings.recurring.income_help', ['minor' => User::DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR, 'example' => Money::ofMinor(User::DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR, $exampleCurrency)->format()])"
                wire:model="recurringIncomeMinAmountMinor"
                class="max-w-xs"
            />
        </section>

        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.drift.heading') }}</h2>
            {{-- The #drift-threshold anchor is what the drift alert links to,
                 so it wraps the field rather than living on it. --}}
            <div id="drift-threshold">
                <x-core::form-field
                    name="driftAlertThresholdPercent"
                    type="select"
                    :label="Lang::get('core::settings.drift.label')"
                    :hint="Lang::get('core::settings.drift.help')"
                    wire:model="driftAlertThresholdPercent"
                    class="max-w-xs"
                >
                    <option value="1">{{ Lang::get('core::settings.drift.options.1') }}</option>
                    <option value="2">{{ Lang::get('core::settings.drift.options.2') }}</option>
                    <option value="5">{{ Lang::get('core::settings.drift.options.5') }}</option>
                    <option value="10">{{ Lang::get('core::settings.drift.options.10') }}</option>
                    <option value="25">{{ Lang::get('core::settings.drift.options.25') }}</option>
                    <option value="50">{{ Lang::get('core::settings.drift.options.50') }}</option>
                </x-core::form-field>
            </div>
        </section>

        <div class="space-y-1 border-t border-slate-100 pt-6 dark:border-slate-800">
            {{-- Above the button rather than beside the field: the reader is
                 looking down here, having just pressed Save, and the strip is
                 the answer to that press. --}}
            @if ($confirmingPeriodMove)
                <x-core::confirm-strip
                    class="mb-3 max-w-xl"
                    :question="Lang::get('core::settings.period.move_confirm', ['day' => $periodStartDay])"
                    :cancel-label="Lang::get('core::settings.period.move_cancel')"
                    :confirm-label="Lang::get('core::settings.period.move_apply')"
                    cancel="cancelPeriodMove"
                    confirm="save"
                />
            @endif
            <button
                type="submit"
                class="block w-full max-w-xs bg-emerald-700 hover:bg-emerald-800 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800 dark:focus-visible:ring-emerald-500"
            >
                {{ Lang::get('core::settings.save') }}
            </button>
            @if ($saved)
                <p wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400">{{ Lang::get('core::settings.saved') }}</p>
            @endif
        </div>
    </form>

    {{-- ===== Account currency — the denomination each account reports in ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-3" id="account-currency">
            <h2 class="{{ $cardHead }}">{{ Lang::get('ledger::account_currency.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('ledger::account_currency.intro') }}
            </p>

            @if (count($accounts ?? []) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('ledger::account_currency.no_accounts') }}</p>
            @else
                <div class="space-y-3">
                    @foreach ($accounts as $account)
                        @livewire('ledger.account-currency-editor', [
                            'accountId' => $account['id'],
                            'accountName' => $account['name'],
                            'currency' => $account['default_currency'],
                        ], key('account-currency-editor-' . $account['id']))
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <h2 class="{{ $groupHead }}">{{ Lang::get('core::settings.groups.insights') }}</h2>

    {{-- ===== Anomaly detection (sensitivity, floor, suppression rules) ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="anomaly-detection">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.anomaly_heading') }}</h2>
            @livewire('anomaly.settings-section')
        </section>
    </div>

    {{-- ===== Notifications — anchored next to Anomaly detection ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="notifications-settings">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.notifications_heading') }}</h2>
            @livewire('notifications.settings-section')
        </section>
    </div>

    {{-- ===== Forecasting ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-3" id="forecast-buffers">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.forecasting.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.forecasting.intro') }}
            </p>

            @if (count($accounts ?? []) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.forecasting.no_accounts') }}</p>
            @else
                <div class="space-y-3">
                    @foreach ($accounts as $account)
                        @livewire('forecasting.opening-balance-editor', [
                            'accountId' => $account['id'],
                            'accountName' => $account['name'],
                            'accountKind' => $account['kind'],
                            'currentOpeningMinor' => $account['opening_balance_minor'],
                            'currentAsOfDate' => $account['opening_balance_as_of_date'],
                            'currency' => $account['default_currency'],
                        ], key('opening-balance-editor-' . $account['id']))
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    {{-- Open banking, auto-import and backup/restore used to sit here. They
         answer "where does my data come from and where does it go", which is
         the Data & Devices page, not a preference screen. Aliases stays: it
         is about how descriptors are *named*, which is a preference. --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="aliases">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.aliases.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.aliases.intro') }}
            </p>
            <x-core::secondary-button
                :href="route('settings.aliases')"
                size="sm"
            >{{ Lang::get('core::settings.aliases.manage') }}</x-core::secondary-button>
        </section>
    </div>

    {{-- ===== Tax — deduction categories ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="tax-settings">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.tax_heading') }}</h2>
            @livewire('tax.settings-section')
        </section>
    </div>

    <h2 class="{{ $groupHead }}">{{ Lang::get('core::settings.groups.app') }}</h2>

    {{-- ===== Help & about ===== --}}
    <div class="{{ $card }} space-y-8">
        <section class="space-y-2" id="about-updates">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.about_updates.heading') }}</h2>
            {{-- The desktop updates itself; a phone is updated by its store.
                 Both keep their own sentence rather than sharing a vaguer one. --}}
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get($onPhone ? 'core::settings.about_updates.body_phone' : 'core::settings.about_updates.body') }}
            </p>
            <x-core::secondary-button
                size="sm"
                wire:click="openReleasesPage"
            >{{ Lang::get('core::settings.about_updates.open_releases') }}</x-core::secondary-button>
        </section>

        {{-- The codes are the only way back into a locked-out account, and
             before this they had exactly one appearance: the post-signup
             ceremony. Nothing in the app linked to them afterwards, so a user
             who skipped past that screen had no route to them at all. --}}
        <section class="space-y-2" id="recovery-codes">
            <h2 class="{{ $cardHead }}">{{ Lang::get('auth::recovery_codes.settings.heading') }}</h2>
            @livewire('auth.recovery-codes-section')
        </section>

        {{-- Both stores require the privacy policy to be reachable inside the
             app, not only from the store listing. The URL is printed beside
             the link because a WebView shell is free to swallow target=_blank,
             and an unreachable policy is the same rejection as a missing one. --}}
        <section class="space-y-2" id="privacy-policy">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.privacy.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.privacy.body') }}
            </p>
            <x-core::secondary-button
                :href="LegalLinks::PRIVACY_POLICY_URL"
                size="sm"
                target="_blank"
                rel="noopener noreferrer"
                data-testid="privacy-policy-link"
            >{{ Lang::get('core::settings.privacy.open') }}</x-core::secondary-button>
            <p class="text-xs text-[var(--color-text-muted)]">
                {{ Lang::get('core::settings.privacy.url_hint') }}
                <span class="select-all font-mono">{{ LegalLinks::PRIVACY_POLICY_URL }}</span>
            </p>
        </section>

        <section class="space-y-2" id="first-run-tour">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.first_run_tour.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.first_run_tour.body') }}
            </p>
            <x-core::secondary-button
                :href="URL::signedRoute('setup', ['force' => 1], absolute: false)"
                size="sm"
            >{{ Lang::get('core::settings.first_run_tour.run_again') }}</x-core::secondary-button>
        </section>
    </div>

    {{-- ===== Delete account ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="delete-account">
            <h2 class="{{ $cardHead }}">{{ Lang::get('auth::delete_account.heading') }}</h2>
            @livewire('auth.delete-account-section')
        </section>
    </div>

    {{-- ===== Advanced (developer) ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="developer-mode">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.developer.heading') }}</h2>
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="text-sm text-[var(--color-text)]">{{ Lang::get('core::settings.developer.label') }}</p>
                    <p class="mt-1 text-xs text-[var(--color-text-muted)]">
                        {{ Lang::get('core::settings.developer.help') }}
                    </p>
                </div>
                <x-core::switch
                    :on="$isDeveloper"
                    :label="Lang::get('core::settings.developer.aria')"
                    wire:click="setDevMode({{ $isDeveloper ? 'false' : 'true' }})"
                />
            </div>
        </section>
    </div>
</div>
