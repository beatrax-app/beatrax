<div class="max-w-md mx-auto space-y-12" data-testid="settings-page">
    <header class="space-y-1">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900">Settings</h1>
        <p class="text-sm text-slate-500">Preferences for how your finances appear in the app.</p>
    </header>

    <form wire:submit="save" class="space-y-12">
        <section class="space-y-2">
            <h2 class="text-xs uppercase tracking-wide text-slate-500">Currency display</h2>
            <div class="space-y-1">
                <label for="defaultCurrencyView" class="block text-sm text-slate-900">Default view on the transactions list</label>
                <select
                    id="defaultCurrencyView"
                    name="defaultCurrencyView"
                    wire:model="defaultCurrencyView"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                >
                    <option value="eur_only">EUR only</option>
                    <option value="original">Original currency</option>
                </select>
                <p class="text-xs text-slate-500">You can still switch per page from the transactions list.</p>
                @error('defaultCurrencyView')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="text-xs uppercase tracking-wide text-slate-500">Period</h2>
            <div class="space-y-1">
                <label for="periodStartDay" class="block text-sm text-slate-900">Period starts on day</label>
                <input
                    type="number"
                    min="1"
                    max="28"
                    id="periodStartDay"
                    name="periodStartDay"
                    wire:model="periodStartDay"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
                <p class="text-xs text-slate-500">Numbered 1 to 28. Most users keep this on 1 (calendar month). Use 25 if your salary lands on the 25th and you think of "your month" as starting then.</p>
                @error('periodStartDay')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-xs uppercase tracking-wide text-slate-500">Recurring detection</h2>
            <div class="space-y-1">
                <label for="recurringDetectionWindowMonths" class="block text-sm text-slate-900">Detection window (months)</label>
                <input
                    type="number"
                    min="3"
                    max="60"
                    id="recurringDetectionWindowMonths"
                    name="recurringDetectionWindowMonths"
                    wire:model="recurringDetectionWindowMonths"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
                <p class="text-xs text-slate-500">How many months of history to scan when clustering transactions into recurring patterns.</p>
                @error('recurringDetectionWindowMonths')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="space-y-1">
                <label for="recurringIncomeMinAmountMinor" class="block text-sm text-slate-900">Income minimum (cents)</label>
                <input
                    type="number"
                    min="0"
                    max="100000000"
                    id="recurringIncomeMinAmountMinor"
                    name="recurringIncomeMinAmountMinor"
                    wire:model="recurringIncomeMinAmountMinor"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
                <p class="text-xs text-slate-500">Incomes below this threshold are not auto-clustered. Stored in cents — 200000 means €2,000.00. Set to 0 to disable the threshold.</p>
                @error('recurringIncomeMinAmountMinor')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="text-xs uppercase tracking-wide text-slate-500">Drift alerts</h2>
            <div class="space-y-1" id="drift-threshold">
                <label for="driftAlertThresholdPercent" class="block text-sm text-slate-900">Default drift alert threshold</label>
                <select
                    id="driftAlertThresholdPercent"
                    name="driftAlertThresholdPercent"
                    wire:model="driftAlertThresholdPercent"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                >
                    <option value="1">±1%</option>
                    <option value="2">±2%</option>
                    <option value="5">±5% (default)</option>
                    <option value="10">±10%</option>
                    <option value="25">±25%</option>
                    <option value="50">±50%</option>
                </select>
                <p class="text-xs text-slate-500">Alerts fire when a recurring charge's latest amount differs from the prior amount by more than this percentage. Per-series overrides take precedence.</p>
                @error('driftAlertThresholdPercent')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <div class="space-y-1">
            <button
                type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
            >
                Save settings
            </button>
            @if ($saved)
                <p wire:transition.duration.4000ms class="text-sm text-emerald-700">Saved.</p>
            @endif
        </div>
    </form>

    {{-- Watched-folder secondary path.

         Instant-apply toggle (no Save button) — toggling fires
         wire:change="toggleAutoImport" which flips the property and
         writes users.auto_import_drop_folder via the raw query
         builder in one round-trip. Help text renders the per-user
         path (storage/app/inbox-drop/{userId}/) because the scanner
         only walks that subfolder. --}}
    <section class="space-y-2">
        <h2 class="text-xs uppercase tracking-wide text-slate-500">Auto-import</h2>
        <div class="space-y-2">
            <label for="auto-import-toggle" class="flex items-start gap-3 cursor-pointer">
                <input
                    type="checkbox"
                    id="auto-import-toggle"
                    @checked($autoImportFromDropFolder)
                    wire:change="toggleAutoImport"
                    aria-describedby="auto-import-help"
                    class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600"
                />
                <div class="flex-1">
                    <span class="block text-sm text-slate-900">Auto-import from drop folder</span>
                    <p id="auto-import-help" class="mt-1 text-xs text-slate-500">
                        @if ($autoImportFromDropFolder)
                            Drop folder is active. diederik scans <code class="font-mono text-slate-700">storage/app/inbox-drop/{{ $userId }}/</code> every 5 minutes for new files.
                        @else
                            When on, diederik scans <code class="font-mono text-slate-700">storage/app/inbox-drop/{{ $userId }}/</code> every 5 minutes for <code class="font-mono text-slate-700">.eml</code> and <code class="font-mono text-slate-700">.mbox</code> files and imports them through the same matcher pipeline as the wizard. Processed files move to <code class="font-mono text-slate-700">/processed/{YYYY-MM}/</code> so they're never imported twice.
                        @endif
                    </p>
                </div>
            </label>
        </div>
    </section>
</div>
