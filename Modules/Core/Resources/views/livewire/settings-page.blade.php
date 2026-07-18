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
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Settings</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">Preferences for how your finances appear in the app.</p>
    </header>

    {{-- ===== Appearance ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">Appearance</h2>
            <div class="space-y-1">
                <span class="block text-sm text-slate-900 dark:text-slate-100">Theme</span>
                <div role="radiogroup" aria-label="Theme" class="inline-flex rounded-md border border-slate-200 dark:border-slate-700 overflow-hidden">
                    @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
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
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">System follows your operating system's light or dark setting.</p>
                @error('theme')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>
    </div>

    {{-- ===== Preferences (batch save) ===== --}}
    <form wire:submit="save" class="{{ $card }} space-y-8">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">Currency display</h2>
            <div class="space-y-1">
                <label for="defaultCurrencyView" class="block text-sm text-slate-900 dark:text-slate-100">Default view on the transactions list</label>
                <select
                    id="defaultCurrencyView"
                    name="defaultCurrencyView"
                    wire:model="defaultCurrencyView"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                >
                    <option value="eur_only">EUR only</option>
                    <option value="original">Original currency</option>
                </select>
                <p class="text-xs text-slate-500 dark:text-slate-400">You can still switch per page from the transactions list.</p>
                @error('defaultCurrencyView')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>

        {{-- ===== Currency reporting (FX base currency + online fetch toggle) ===== --}}
        <section class="space-y-6">
            {{-- Sub-section A: Base reporting currency picker --}}
            <div class="space-y-2">
                <h2 class="{{ $cardHead }}">Base reporting currency</h2>
                <div class="space-y-1">
                    <label for="baseCurrency" class="block text-sm text-slate-900 dark:text-slate-100">Reporting currency</label>
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
                    <p class="text-xs text-slate-500 dark:text-slate-400">All totals and roll-ups convert to this currency. Each account still shows its own original currency alongside.</p>
                    @error('baseCurrency')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Sub-section B: Online exchange-rate fetch toggle --}}
            <div class="space-y-3">
                <h2 class="{{ $cardHead }}">Exchange rates</h2>
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <p class="text-sm text-[var(--color-text)]">Fetch current rates online</p>
                        <p class="mt-1 text-xs text-[var(--color-text-muted)]">
                            @if ($fxOnlineEnabled)
                                Rates fetched from ECB daily. Only currency pair lookups — no personal data.
                                @if ($fxLastUpdated)
                                    Last updated: {{ $fxLastUpdated }}.
                                @endif
                            @else
                                Bundled rates are used. No data leaves your machine.
                            @endif
                        </p>
                    </div>
                    <button type="button"
                            class="switch{{ $fxOnlineEnabled ? ' switch--on' : '' }}"
                            wire:click="toggleFxOnline"
                            aria-pressed="{{ $fxOnlineEnabled ? 'true' : 'false' }}"
                            aria-label="Fetch current exchange rates online">
                        <span class="switch__thumb"></span>
                    </button>
                </div>

                @if ($fxOnlineEnabled)
                    <div wire:transition class="flex items-center justify-between gap-3">
                        <p class="text-xs" style="color: var(--color-text-faint);">
                            @if ($fxRefreshing)
                                Refreshing&hellip;
                            @else
                                Next auto-refresh: daily at 09:00
                            @endif
                        </p>
                        <button
                            type="button"
                            wire:click="refreshFxRates"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800 disabled:opacity-50"
                        >Refresh now</button>
                    </div>
                @endif
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">Period</h2>
            <div class="space-y-1">
                <label for="periodStartDay" class="block text-sm text-slate-900 dark:text-slate-100">Period starts on day</label>
                <input
                    type="number"
                    min="1"
                    max="28"
                    id="periodStartDay"
                    name="periodStartDay"
                    wire:model="periodStartDay"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">Numbered 1 to 28. Most users keep this on 1 (calendar month). Use 25 if your salary lands on the 25th and you think of "your month" as starting then.</p>
                @error('periodStartDay')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="{{ $cardHead }}">Recurring detection</h2>
            <div class="space-y-1">
                <label for="recurringDetectionWindowMonths" class="block text-sm text-slate-900 dark:text-slate-100">Detection window (months)</label>
                <input
                    type="number"
                    min="2"
                    max="60"
                    id="recurringDetectionWindowMonths"
                    name="recurringDetectionWindowMonths"
                    wire:model="recurringDetectionWindowMonths"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">How many months of history to scan when clustering transactions into recurring patterns.</p>
                @error('recurringDetectionWindowMonths')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
            <div class="space-y-1">
                <label for="recurringIncomeMinAmountMinor" class="block text-sm text-slate-900 dark:text-slate-100">Income minimum (cents)</label>
                <input
                    type="number"
                    min="0"
                    max="100000000"
                    id="recurringIncomeMinAmountMinor"
                    name="recurringIncomeMinAmountMinor"
                    wire:model="recurringIncomeMinAmountMinor"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">Incomes below this threshold are not auto-clustered. Stored in cents — 200000 means €2,000.00. Set to 0 to disable the threshold.</p>
                @error('recurringIncomeMinAmountMinor')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">Drift alerts</h2>
            <div class="space-y-1" id="drift-threshold">
                <label for="driftAlertThresholdPercent" class="block text-sm text-slate-900 dark:text-slate-100">Default drift alert threshold</label>
                <select
                    id="driftAlertThresholdPercent"
                    name="driftAlertThresholdPercent"
                    wire:model="driftAlertThresholdPercent"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                >
                    <option value="1">±1%</option>
                    <option value="2">±2%</option>
                    <option value="5">±5% (default)</option>
                    <option value="10">±10%</option>
                    <option value="25">±25%</option>
                    <option value="50">±50%</option>
                </select>
                <p class="text-xs text-slate-500 dark:text-slate-400">Alerts fire when a recurring charge's latest amount differs from the prior amount by more than this percentage. Per-series overrides take precedence.</p>
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
                Save settings
            </button>
            @if ($saved)
                <p wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400">Saved.</p>
            @endif
        </div>
    </form>

    {{-- ===== Anomaly detection (sensitivity, floor, suppression rules) ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="anomaly-detection">
            <h2 class="{{ $cardHead }}">Anomaly detection</h2>
            @livewire('anomaly.settings-section')
        </section>
    </div>

    {{-- ===== Notifications (D-36) — anchored next to Anomaly detection ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="notifications-settings">
            <h2 class="{{ $cardHead }}">Notifications</h2>
            @livewire('notifications.settings-section')
        </section>
    </div>

    {{-- ===== Forecasting ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-3" id="forecast-buffers">
            <h2 class="{{ $cardHead }}">Forecasting</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                beatrax projects your balance forward from your accounts' current state. For accounts without statement balances (PayPal, legacy CSV imports), set the opening balance here so projections start from a known point.
            </p>

            @if (count($forecastingAccounts ?? []) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">No accounts yet — import a statement to add one.</p>
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

    {{-- ===== Importing (auto-import + aliases) ===== --}}
    <div class="{{ $card }} space-y-8">
        <section class="space-y-2">
            <h2 class="{{ $cardHead }}">Auto-import</h2>
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
                        <span class="block text-sm text-slate-900 dark:text-slate-100">Auto-import from drop folder</span>
                        <p id="auto-import-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            @if ($autoImportFromDropFolder)
                                Drop folder is active. beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/{{ $userId }}/</code> every 5 minutes for new files.
                            @else
                                When on, beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/{{ $userId }}/</code> every 5 minutes for <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> and <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> files and imports them through the same matcher pipeline as the wizard. Processed files move to <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> so they're never imported twice.
                            @endif
                        </p>
                    </div>
                </label>
            </div>
        </section>

        <section class="space-y-2" id="aliases">
            <h2 class="{{ $cardHead }}">Aliases</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Review and edit the friendly names you've taught beatrax for cryptic statement descriptions.
            </p>
            <a
                href="{{ route('settings.aliases') }}"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >Manage aliases →</a>
        </section>
    </div>

    {{-- ===== Tax — country + deduction categories ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="tax-settings">
            <h2 class="{{ $cardHead }}">Tax</h2>
            @livewire('tax.settings-section')
        </section>
    </div>

    {{-- ===== Shared merchant list ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="shared-merchant-list">
            <h2 class="{{ $cardHead }}">Shared merchant list</h2>
            @livewire('community.shared-list-settings-panel')
        </section>
    </div>

    {{-- ===== Data & backup ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="data-backup">
            <h2 class="{{ $cardHead }}">Data &amp; backup</h2>
            @livewire('core.encrypted-backup-download')
            @livewire('core.encrypted-backup-restore')
        </section>
    </div>

    {{-- ===== Install (D-22 placement b) ===== --}}
    {{-- Standing install row: "Install beatrax as an app" — copy and CTA
         logic owned by the x-core::install-hint component. --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="install-app">
            <h2 class="{{ $cardHead }}">Install</h2>
            <x-core::install-hint />
        </section>
    </div>

    {{-- ===== Help & about ===== --}}
    <div class="{{ $card }} space-y-8">
        <section class="space-y-2" id="about-updates">
            <h2 class="{{ $cardHead }}">About updates</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                beatrax updates itself automatically once installed. After installing the very first version, future versions arrive via an in-app banner — you don't need to revisit GitHub. If a future update ever fails to apply, you can always re-download the latest installer manually from the releases page.
            </p>
            <button
                type="button"
                wire:click="openReleasesPage"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >Open releases page →</button>
        </section>

        <section class="space-y-2" id="first-run-tour">
            <h2 class="{{ $cardHead }}">First-run tour</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Re-launch the setup wizard if you want to walk back through the introductory flow.
            </p>
            <a
                href="{{ route('setup', ['force' => 1]) }}"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >Run setup wizard again</a>
        </section>
    </div>

    {{-- ===== Advanced (developer) ===== --}}
    <div class="{{ $card }}">
        <section class="space-y-2" id="developer-mode">
            <h2 class="{{ $cardHead }}">Developer</h2>
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="text-sm text-[var(--color-text)]">In-app Dev Console</p>
                    <p class="mt-1 text-xs text-[var(--color-text-muted)]">
                        Show the Dev Console at /dev. Resets the Advanced toggle on every login.
                    </p>
                </div>
                <button type="button"
                        class="switch{{ $isDeveloper ? ' switch--on' : '' }}"
                        wire:click="setDevMode({{ $isDeveloper ? 'false' : 'true' }})"
                        aria-pressed="{{ $isDeveloper ? 'true' : 'false' }}"
                        aria-label="Developer mode">
                    <span class="switch__thumb"></span>
                </button>
            </div>
        </section>
    </div>
</div>
