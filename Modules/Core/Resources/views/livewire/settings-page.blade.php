@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Enums\Theme')
@php
    // Shared card chrome for the grouped settings sections. The redesign only
    // changes the visual container — every control, id, wire: binding, @error
    // block and copy string below is preserved verbatim from the flat layout.
    $card = 'rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950';
    $cardHead = 'text-xs uppercase tracking-wide text-[var(--color-text-faint)]';
@endphp

{{--
    Phone responsive pass (UI-SPEC §19, D-22).
    At <768px: max-width constraint is removed; .settings-grid collapses to a
    single column. Desktop multi-column unchanged.
    The .settings-grid responsive CSS rule lives in resources/css/app.css
    (Phase 4 per-page responsive section).
--}}

<div class="max-w-2xl mx-auto space-y-6" data-testid="settings-page">
    <header class="space-y-1">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.title') }}</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.subtitle') }}</p>
    </header>

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
                <span class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.language.label') }}</span>
                <div role="radiogroup" aria-label="{{ Lang::get('core::settings.language.heading') }}" class="inline-flex rounded-md border border-slate-200 dark:border-slate-700 overflow-hidden">
                    @foreach (['auto' => Lang::get('core::settings.language.system'), 'en' => 'English', 'nl' => 'Nederlands'] as $value => $label)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $locale === $value ? 'true' : 'false' }}"
                            wire:click="setLocale('{{ $value }}')"
                            @class([
                                'px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100',
                                'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' => $locale === $value,
                                'bg-white text-slate-900 hover:bg-slate-50 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-900' => $locale !== $value,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.language.help') }}</p>
                @error('locale')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>
    </div>

    {{-- ===== Preferences (batch save) ===== --}}
    <form wire:submit="save" class="{{ $card }} space-y-8">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.currency_display.heading') }}</h2>
            <div class="space-y-1">
                <label for="defaultCurrencyView" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.currency_display.label') }}</label>
                <select
                    id="defaultCurrencyView"
                    name="defaultCurrencyView"
                    wire:model="defaultCurrencyView"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                >
                    <option value="eur_only">{{ Lang::get('core::settings.currency_display.eur_only') }}</option>
                    <option value="original">{{ Lang::get('core::settings.currency_display.original') }}</option>
                </select>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.currency_display.help') }}</p>
                @error('defaultCurrencyView')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>

        {{-- ===== Currency reporting (FX base currency + online fetch toggle) ===== --}}
        <section class="space-y-6">
            {{-- Sub-section A: Base reporting currency picker --}}
            <div class="space-y-2">
                <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.base_currency.heading') }}</h2>
                <div class="space-y-1">
                    <label for="baseCurrency" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.base_currency.label') }}</label>
                    <select
                        id="baseCurrency"
                        name="baseCurrency"
                        wire:model="baseCurrency"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                    >
                        @foreach ($currencyOptions as $code => $name)
                            <option value="{{ $code }}">{{ $code }} — {{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.base_currency.help') }}</p>
                    @error('baseCurrency')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                    @enderror
                </div>
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
                    <button type="button"
                            class="switch{{ $fxOnlineEnabled ? ' switch--on' : '' }}"
                            wire:click="toggleFxOnline"
                            aria-pressed="{{ $fxOnlineEnabled ? 'true' : 'false' }}"
                            aria-label="{{ Lang::get('core::settings.exchange_rates.fetch_aria') }}">
                        <span class="switch__thumb"></span>
                    </button>
                </div>

                @if ($fxOnlineEnabled)
                    <div wire:transition class="flex items-center justify-between gap-3">
                        <p class="text-xs" style="color: var(--color-text-faint);">
                            @if ($fxRefreshing)
                                {{ Lang::get('core::settings.exchange_rates.refreshing') }}
                            @else
                                {{ Lang::get('core::settings.exchange_rates.next_refresh') }}
                            @endif
                        </p>
                        <button
                            type="button"
                            wire:click="refreshFxRates"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800 disabled:opacity-50"
                        >{{ Lang::get('core::settings.exchange_rates.refresh_now') }}</button>
                    </div>
                @endif
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.period.heading') }}</h2>
            <div class="space-y-1">
                <label for="periodStartDay" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.period.label') }}</label>
                <input
                    type="number"
                    min="1"
                    max="28"
                    id="periodStartDay"
                    name="periodStartDay"
                    wire:model="periodStartDay"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.period.help') }}</p>
                @error('periodStartDay')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.recurring.heading') }}</h2>
            <div class="space-y-1">
                <label for="recurringDetectionWindowMonths" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.recurring.window_label') }}</label>
                <input
                    type="number"
                    min="2"
                    max="60"
                    id="recurringDetectionWindowMonths"
                    name="recurringDetectionWindowMonths"
                    wire:model="recurringDetectionWindowMonths"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.recurring.window_help') }}</p>
                @error('recurringDetectionWindowMonths')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
            <div class="space-y-1">
                <label for="recurringIncomeMinAmountMinor" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.recurring.income_label') }}</label>
                <input
                    type="number"
                    min="0"
                    max="100000000"
                    id="recurringIncomeMinAmountMinor"
                    name="recurringIncomeMinAmountMinor"
                    wire:model="recurringIncomeMinAmountMinor"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.recurring.income_help') }}</p>
                @error('recurringIncomeMinAmountMinor')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.drift.heading') }}</h2>
            <div class="space-y-1" id="drift-threshold">
                <label for="driftAlertThresholdPercent" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.drift.label') }}</label>
                <select
                    id="driftAlertThresholdPercent"
                    name="driftAlertThresholdPercent"
                    wire:model="driftAlertThresholdPercent"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                >
                    <option value="1">{{ Lang::get('core::settings.drift.options.1') }}</option>
                    <option value="2">{{ Lang::get('core::settings.drift.options.2') }}</option>
                    <option value="5">{{ Lang::get('core::settings.drift.options.5') }}</option>
                    <option value="10">{{ Lang::get('core::settings.drift.options.10') }}</option>
                    <option value="25">{{ Lang::get('core::settings.drift.options.25') }}</option>
                    <option value="50">{{ Lang::get('core::settings.drift.options.50') }}</option>
                </select>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.drift.help') }}</p>
                @error('driftAlertThresholdPercent')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <div class="space-y-1 border-t border-slate-100 pt-6 dark:border-slate-800">
            <button
                type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400 dark:focus-visible:ring-emerald-500"
            >
                {{ Lang::get('core::settings.save') }}
            </button>
            @if ($saved)
                <p wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400">{{ Lang::get('core::settings.saved') }}</p>
            @endif
        </div>
    </form>

    {{-- ===== Anomaly detection (sensitivity, floor, suppression rules) ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="anomaly-detection">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.anomaly_heading') }}</h2>
            @livewire('anomaly.settings-section')
        </section>
    </div>

    {{-- ===== Notifications (D-36) — anchored next to Anomaly detection ===== --}}
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

            @if (count($forecastingAccounts ?? []) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.forecasting.no_accounts') }}</p>
            @else
                <div class="space-y-3">
                    @foreach ($forecastingAccounts as $account)
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

    {{-- ===== App lock (PIN-based session lock, plan 05-04) ===== --}}
    <div class="{{ $card }}">
        <section id="app-lock">
            @livewire('auth.app-lock-settings-section')
        </section>
    </div>

    {{-- ===== Devices & Sync (device identity + pairing, Phase 12) ===== --}}
    <div class="{{ $card }}">
        <section id="devices-sync">
            @livewire('sync.devices-and-sync-settings-section')
        </section>
    </div>

    {{-- ===== Open banking (Phase 19) — Aliases link-out shape, Surface A ===== --}}
    <div class="{{ $card }}">
        <section id="open-banking">
            @livewire('openbanking.open-banking-status-row')
        </section>
    </div>

    {{-- ===== Importing (auto-import + aliases) ===== --}}
    <div class="{{ $card }} space-y-8">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.auto_import.heading') }}</h2>
            <div class="space-y-2">
                <label for="auto-import-toggle" class="flex items-start gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        id="auto-import-toggle"
                        @checked($autoImportFromDropFolder)
                        wire:change="toggleAutoImport"
                        aria-describedby="auto-import-help"
                        class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-600 dark:text-emerald-500 dark:focus:ring-emerald-500"
                    />
                    <div class="flex-1">
                        <span class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.auto_import.label') }}</span>
                        <p id="auto-import-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            @if ($autoImportFromDropFolder)
                                {!! Lang::get('core::settings.auto_import.active_html', ['userId' => $userId]) !!}
                            @else
                                {!! Lang::get('core::settings.auto_import.inactive_html', ['userId' => $userId]) !!}
                            @endif
                        </p>
                    </div>
                </label>
            </div>
        </section>

        <section class="space-y-2" id="aliases">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.aliases.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.aliases.intro') }}
            </p>
            <a
                href="{{ route('settings.aliases') }}"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >{{ Lang::get('core::settings.aliases.manage') }}</a>
        </section>
    </div>

    {{-- ===== Tax — country + deduction categories ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="tax-settings">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.tax_heading') }}</h2>
            @livewire('tax.settings-section')
        </section>
    </div>

    {{-- ===== Shared merchant list ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="shared-merchant-list">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.shared_merchant_heading') }}</h2>
            @livewire('community.shared-list-settings-panel')
        </section>
    </div>

    {{-- ===== Data & backup ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="data-backup">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.data_backup_heading') }}</h2>
            @livewire('core.encrypted-backup-download')
            @livewire('core.encrypted-backup-restore')
        </section>
    </div>

    {{-- ===== Install (D-22 placement b) ===== --}}
    {{-- Standing install row: "Install beatrax as an app" — copy and CTA
         logic owned by the x-core::install-hint component. --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="install-app">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.install_heading') }}</h2>
            <x-core::install-hint />
        </section>
    </div>

    {{-- ===== Help & about ===== --}}
    <div class="{{ $card }} space-y-8">
        <section class="space-y-2" id="about-updates">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.about_updates.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.about_updates.body') }}
            </p>
            <button
                type="button"
                wire:click="openReleasesPage"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >{{ Lang::get('core::settings.about_updates.open_releases') }}</button>
        </section>

        <section class="space-y-2" id="first-run-tour">
            <h2 class="{{ $cardHead }}">{{ Lang::get('core::settings.first_run_tour.heading') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.first_run_tour.body') }}
            </p>
            <a
                href="{{ route('setup', ['force' => 1]) }}"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >{{ Lang::get('core::settings.first_run_tour.run_again') }}</a>
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
                <button type="button"
                        class="switch{{ $isDeveloper ? ' switch--on' : '' }}"
                        wire:click="setDevMode({{ $isDeveloper ? 'false' : 'true' }})"
                        aria-pressed="{{ $isDeveloper ? 'true' : 'false' }}"
                        aria-label="{{ Lang::get('core::settings.developer.aria') }}">
                    <span class="switch__thumb"></span>
                </button>
            </div>
        </section>
    </div>
</div>
