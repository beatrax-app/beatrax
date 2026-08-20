{{--
    Dedicated `/sync` status surface (D-05/D-06, 15-UI-SPEC.md §3).
    Composition top-to-bottom: page title (Display role) -> "Your devices"
    section (Heading role) embedding the EXISTING per-peer status
    component UNCHANGED below (reuse, not rebuild; overall banner +
    per-device list) + this screen's own initial-sync "{n} of {m} records"
    progress line -> "Sync now" primary CTA (accent-ink, min-h-44px) ->
    "Network" section (Heading role) with the "Pause sync on cellular"
    toggle (D-10, `.switch`/`.switch--on` markup reused verbatim from
    devices-and-sync-settings-section.blade.php).

    No `font-bold` anywhere (project caps at semibold/600, UI-SPEC
    Typography). Accent (slate-900/slate-100) reserved for the CTA only —
    status communication routes through the embedded component's own
    semantic colors, never through this ink accent.
--}}
@use('Modules\Core\Public\Support\Lang')
<div class="max-w-lg mx-auto px-6 py-8 space-y-6" data-testid="sync-screen">

    <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::sync.heading') }}</h1>

    <section class="space-y-3">
        {{-- "Sync status", not "Your devices": the devices section below owns
             that heading for the list you actually manage, and having the
             same label twice on one page made the two read as duplicates of
             each other rather than as status vs. management. --}}
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::sync.sync_status') }}</h2>

        {{-- Reuse, not rebuild: the existing component owns the overall
             banner (idle/syncing/offline/error) + the per-device list. It is
             rendered HERE and nowhere else — the devices section used to
             embed it a second time, which put two identical status banners
             on this page. --}}
        @livewire('sync.sync-status-section')

        @if ($initialSyncInProgress)
            <div class="space-y-2" data-testid="sync-initial-progress">
                <p class="text-sm text-slate-600 dark:text-slate-400" aria-live="polite">
                    {{ Lang::get('mobile::sync.syncing_progress', ['count' => $progressApplied]) }}
                </p>
                <div
                    class="h-2 w-full rounded-full bg-slate-200 dark:bg-slate-700"
                    role="progressbar"
                    aria-valuenow="{{ $progressPercent }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="{{ Lang::get('mobile::sync.initial_sync_aria') }}"
                >
                    <div class="h-2 rounded-full bg-slate-900 dark:bg-slate-100" style="width: {{ $progressPercent }}%"></div>
                </div>
            </div>
        @endif
    </section>

    {{-- ===== "Sync now" primary CTA (D-08) — accent-ink, min-h-44px ===== --}}
    {{-- Inert until a peer exists. With no confirmed device the burst dials
         nobody and returns cleanly, so an enabled button reported success on a
         device that had never been paired. --}}
    <button
        type="button"
        wire:click="syncNow"
        @disabled(! $hasPeers)
        aria-disabled="{{ $hasPeers ? 'false' : 'true' }}"
        @class([
            'w-full min-h-[44px] rounded-md px-4 py-2.5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100',
            'bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200' => $hasPeers,
            'bg-slate-200 text-slate-400 cursor-not-allowed dark:bg-slate-800 dark:text-slate-500' => ! $hasPeers,
        ])
        data-testid="sync-now-button"
    >
        {{ Lang::get('mobile::sync.sync_now') }}
    </button>

    @if (! $hasPeers)
        <p class="-mt-4 text-xs text-slate-500 dark:text-slate-400" data-testid="sync-no-peers">{{ Lang::get('mobile::sync.no_peers') }}</p>
    @endif

    {{-- Device identity, pairing and the encryption controls. This is the
         canonical home for them: they are about the sync relationship
         between devices, and Settings only ever linked to the same
         component. Embedded by alias, the same cross-module seam the
         status section above already uses. --}}
    <section class="space-y-3" data-testid="sync-devices-management">
        @livewire('sync.devices-and-sync-settings-section')
    </section>

    {{-- App lock (PIN, idle timeout, biometric unlock). It lives here rather
         than in Settings because it describes THIS device instance, not a
         preference: sync cannot be enabled without it, and the section above
         links straight to it.

         The id is the anchor that link targets. It used to point at a section
         on the Settings page while rendering here, so clicking it did nothing
         — a fragment link only resolves within the current document. --}}
    <section id="app-lock" class="space-y-3" data-testid="sync-app-lock">
        @livewire('auth.app-lock-settings-section')
    </section>

    <section class="space-y-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::sync.network') }}</h2>

        {{-- ===== "Pause sync on cellular" toggle (D-10) ===== --}}
        <x-core::setting-row
            :label="Lang::get('mobile::sync.pause_cellular')"
            :description="Lang::get('mobile::sync.pause_cellular_help')"
        >
            {{-- The visual `.switch` track is 36x20px (existing component, reused
                 verbatim); the wrapping min-w/min-h-[44px] flex box below is the
                 D-14/WCAG 2.5.5 tap-target padding this mobile surface requires
                 (same "small visual, 44px hit target" idiom as Goals/Pots pages'
                 `min-w-[44px] min-h-[44px] flex items-center justify-center`). --}}
            <div class="min-w-[44px] min-h-[44px] flex items-center justify-center">
                <button
                    type="button"
                    wire:click="toggleCellularPause"
                    @class(['switch', 'switch--on' => $pauseOnCellular])
                    aria-pressed="{{ $pauseOnCellular ? 'true' : 'false' }}"
                    aria-label="{{ Lang::get('mobile::sync.pause_cellular') }}"
                >
                    <span class="switch__thumb"></span>
                </button>
            </div>
        </x-core::setting-row>
    </section>

    {{-- The three sections below moved off Settings. They answer the same
         question the rest of this page does — where this install's data comes
         from and where it goes — whereas Settings is for preferences. Order
         runs from the most automatic source to the most manual: a live bank
         connection, then a watched folder, then a file you carry yourself. --}}
    <section class="space-y-3" id="open-banking" data-testid="data-open-banking">
        @livewire('openbanking.open-banking-status-row')
    </section>

    <section class="space-y-3" id="auto-import" data-testid="data-auto-import">
        @livewire('core.auto-import-settings-section')
    </section>

    <section class="space-y-3" id="data-backup" data-testid="data-backup">
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.data_backup_heading') }}</h2>
        @livewire('core.encrypted-backup-download')
        @livewire('core.encrypted-backup-restore')
    </section>

</div>
