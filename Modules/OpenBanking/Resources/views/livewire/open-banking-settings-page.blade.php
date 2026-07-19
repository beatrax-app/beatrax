{{-- `/settings/open-banking` trust surface (19-11, UI-SPEC Surface B). --}}

<div class="max-w-2xl mx-auto space-y-6" data-testid="open-banking-settings-page">
    <a href="{{ route('settings') }}" class="text-sm text-slate-500 hover:underline dark:text-slate-400">&larr; Settings</a>

    <header class="space-y-1">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Open banking</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Automatically fetch transactions from ASN or SNS through Enable Banking, a third-party PSD2 aggregator. Off by default.
        </p>
    </header>

    @if ($flashMessage !== '')
        <div
            role="{{ $flashTone === 'error' ? 'alert' : 'status' }}"
            class="rounded-xl border p-4 text-sm
                {{ $flashTone === 'error'
                    ? 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-300'
                    : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' }}"
            data-testid="ob-page-flash"
        >{{ $flashMessage }}</div>
    @endif

    {{-- ===== B1: header + toggle ===== --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
        <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
                <p class="text-sm text-slate-900 dark:text-slate-100">Enable open banking</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    @if ($enabled)
                        Connected to {{ $bankDisplayName }} via Enable Banking.
                    @else
                        Off by default. Requires a one-time acknowledgement and guided setup.
                    @endif
                </p>
            </div>
            <button
                type="button"
                class="switch{{ $enabled ? ' switch--on' : '' }}"
                wire:click="toggleClicked"
                aria-pressed="{{ $enabled ? 'true' : 'false' }}"
                aria-label="Enable open banking"
                data-testid="ob-toggle"
            >
                <span class="switch__thumb"></span>
            </button>
        </div>
    </div>

    {{-- ===== B5: consent-expiry banner (Req 7/8) — rendered above the
         transparency panel when consent has expired ===== --}}
    @include('openbanking::partials.open-banking-consent-banner')

    {{-- ===== B4: transparency panel (Req 6) ===== --}}
    @include('openbanking::partials.open-banking-transparency-panel')

    {{-- ===== B6: scheduled auto-sync + manual sync (Req 9) — only ever
         rendered while OB is enabled; hidden entirely when off, per
         UI-SPEC's Copywriting Contract ("Sync-now disabled caption (OB
         off) | (button hidden entirely)") ===== --}}
    @if ($enabled)
        <div class="space-y-2" data-testid="open-banking-sync-now">
            @if ($syncFlashMessage !== '')
                <div
                    role="{{ $syncFlashTone === 'error' ? 'alert' : 'status' }}"
                    @if ($syncFlashTone === 'zero') wire:init="$set('syncFlashMessage', '')" wire:transition.duration.4000ms @endif
                    class="rounded-lg border p-3 text-sm
                        {{ $syncFlashTone === 'error'
                            ? 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-300'
                            : ($syncFlashTone === 'success'
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                : 'border-slate-200 bg-white text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400') }}"
                    data-testid="ob-sync-flash"
                >
                    {{ $syncFlashMessage }}
                    @if ($syncFlashTone === 'success' && $syncReviewImportRunId !== null)
                        <a
                            href="{{ route('imports.preview', ['id' => $syncReviewImportRunId]) }}"
                            class="ml-1 font-medium underline"
                            data-testid="ob-review-import-link"
                        >Review import &rarr;</a>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-between gap-3">
                @if ($consentStatus === 'expired')
                    <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="ob-sync-now-disabled-caption">Reconnect first</p>
                @else
                    <p class="text-xs text-slate-500 dark:text-slate-400">Syncs automatically once a day.</p>
                @endif
                <button
                    type="button"
                    wire:click="syncNow"
                    wire:loading.attr="disabled"
                    wire:target="syncNow"
                    @disabled($consentStatus === 'expired')
                    class="inline-flex min-h-[44px] items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white
                           hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           disabled:cursor-not-allowed disabled:opacity-50
                           dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                    data-testid="ob-sync-now-button"
                >
                    <span
                        wire:loading
                        wire:target="syncNow"
                        class="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-white/40 border-t-white dark:border-slate-900/40 dark:border-t-slate-900"
                    ></span>
                    Sync now
                </button>
            </div>
        </div>
    @endif

    @include('openbanking::partials.open-banking-warning-modal')

    {{-- ===== Disconnect confirm — shared by the ON->OFF toggle click and
         the B4 transparency panel's "Disconnect" button ===== --}}
    @if ($showDisconnectModal)
        <flux:modal wire:model="showDisconnectModal" class="md:max-w-sm" data-testid="open-banking-disconnect-modal">
            <div class="space-y-4 p-6">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Disconnect open banking?</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    This removes your stored Enable Banking credentials and consent. Automatic syncing stops immediately. Transactions already imported into beatrax are not affected.
                </p>
                <div class="flex gap-3">
                    <button
                        type="button"
                        wire:click="disconnect"
                        wire:loading.attr="disabled"
                        class="flex-1 min-h-[44px] rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                        data-testid="ob-confirm-disconnect"
                    >Disconnect</button>
                    <button
                        type="button"
                        wire:click="cancelDisconnect"
                        autofocus
                        class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                               hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                        data-testid="ob-cancel-disconnect"
                    >Keep connected</button>
                </div>
            </div>
        </flux:modal>
    @endif

    @livewire('openbanking.open-banking-wizard-modal', key('open-banking-wizard-modal'))
</div>
