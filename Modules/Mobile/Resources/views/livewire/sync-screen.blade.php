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
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::sync.your_devices') }}</h2>

        {{-- Reuse, not rebuild: the existing component owns the overall
             banner (idle/syncing/offline/error) + the per-device list. --}}
        @livewire('sync.sync-status-section')

        @if ($initialSyncInProgress)
            <div class="space-y-2" data-testid="sync-initial-progress">
                <p class="text-sm text-slate-600 dark:text-slate-400" aria-live="polite">
                    {{ Lang::get('mobile::sync.syncing_progress', ['applied' => $progressApplied, 'expected' => $progressExpected ?? $progressApplied]) }}
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
    <button
        type="button"
        wire:click="syncNow"
        class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
               hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
               dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
        data-testid="sync-now-button"
    >
        {{ Lang::get('mobile::sync.sync_now') }}
    </button>

    <section class="space-y-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::sync.network') }}</h2>

        {{-- ===== "Pause sync on cellular" toggle (D-10) ===== --}}
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
                <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::sync.pause_cellular') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('mobile::sync.pause_cellular_help') }}
                </p>
            </div>
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
        </div>
    </section>

</div>
