{{--
    Devices & Sync settings section — UI-SPEC Surface A (Phase 12, D-02/D-09/D-10/D-11/D-12)
    + Phase 13 D-06 sync-status surface + D-01 relay endpoint URL field.

    Mounted into the Core settings page via @livewire('sync.devices-and-sync-settings-section').

    Decisions enforced here:
      - D-02: enable-sync is gated on an app-lock being configured. With no
        app-lock the toggle is dimmed/disabled and an info notice with a
        "Go to App lock" link (-> #app-lock) is shown.
      - D-09: each device row shows an inline-renamable name (hover-reveal pencil).
      - D-10: NO revoke / remove action — view / rename / verify only.
      - D-11: "Pair a new device" opens the pairing-flow modal.
      - D-12: identity is generated only on enable-sync; until then no device list.
      - D-06 (Phase 13): per-peer sync status + overall "up to date · synced Nm ago"
        rendered via @livewire('sync.sync-status-section') when sync is on.
      - D-01 (Phase 13): relay endpoint URL field (default none = LAN-direct);
        non-HTTPS URL shows an insecure-connection warning (T-13-08 / Pitfall 6).
        Writes are gated on app-lock (T-13-18).

    Copywriting + tokens follow UI-SPEC; calm-slate (sketch-findings-beatrax),
    weights 400/600 only, min-h-[44px] on all buttons, JetBrains Mono for the
    safety-number identifiers.
--}}

<div class="space-y-6">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Devices &amp; Sync</h2>

    @if ($flashMessage !== '')
        <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
    @endif

    {{-- ===== Enable-sync toggle row (D-02 gate) ===== --}}
    <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <p class="text-sm text-slate-900 dark:text-slate-100">Enable sync</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Share your data securely across trusted devices. Requires an app lock.
            </p>
        </div>
        <button
            type="button"
            wire:click="{{ $syncEnabled ? '' : ($appLockConfigured ? 'enableSync' : '') }}"
            @class([
                'switch',
                'switch--on' => $syncEnabled,
                'opacity-50 cursor-not-allowed pointer-events-none' => ! $appLockConfigured && ! $syncEnabled,
            ])
            aria-pressed="{{ $syncEnabled ? 'true' : 'false' }}"
            aria-label="Enable sync"
            @disabled(! $appLockConfigured && ! $syncEnabled)
        >
            <span class="switch__thumb"></span>
        </button>
    </div>

    {{-- App-lock gate notice (shown when sync is off and no app-lock is set) --}}
    @if (! $syncEnabled && ! $appLockConfigured)
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300" role="note">
            <p>Set an app lock first to enable sync.</p>
            <a
                href="#app-lock"
                class="mt-1 inline-block text-sm font-semibold text-blue-700 underline-offset-2 hover:underline dark:text-blue-300"
            >
                Go to App lock
            </a>
        </div>
    @endif

    {{-- ===== Device list + Pair CTA (only when sync is ON) ===== --}}
    @if ($syncEnabled)
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Your devices</h3>

            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach ($devices as $device)
                    <li class="py-4" wire:key="device-{{ $device['id'] }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                {{-- Name + inline rename (D-09) --}}
                                @if ($renamingDeviceId === $device['id'])
                                    <div
                                        class="flex items-center gap-2"
                                        x-data
                                        x-init="$nextTick(() => $refs.renameInput && $refs.renameInput.focus())"
                                    >
                                        <input
                                            x-ref="renameInput"
                                            type="text"
                                            wire:model="renameValue"
                                            wire:keydown.enter="renameDevice"
                                            wire:keydown.escape="cancelRename"
                                            placeholder="Device name"
                                            aria-label="Device name"
                                            class="block w-full max-w-xs rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900
                                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                                        />
                                        <button
                                            type="button"
                                            wire:click="renameDevice"
                                            class="min-h-[44px] rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white
                                                   hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                                   dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                                        >
                                            Save
                                        </button>
                                    </div>
                                @else
                                    <div class="group flex items-center gap-2">
                                        <span class="text-sm text-slate-900 dark:text-slate-100">{{ $device['name'] }}</span>
                                        <button
                                            type="button"
                                            wire:click="startRename({{ $device['id'] }})"
                                            aria-label="Rename device"
                                            class="opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100
                                                   text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-1 rounded
                                                   dark:text-slate-500 dark:hover:text-slate-300 dark:focus-visible:ring-slate-100"
                                        >
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path d="M2.695 14.762l-1.262 3.155a.5.5 0 00.65.65l3.155-1.262a4 4 0 001.343-.886L17.5 5.501a2.121 2.121 0 00-3-3L3.58 13.419a4 4 0 00-.885 1.343z" />
                                            </svg>
                                        </button>

                                        @if ($device['is_self'])
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                This device
                                            </span>
                                        @endif

                                        @if ($device['confirmed'])
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                Confirmed
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                Awaiting confirmation
                                            </span>
                                        @endif
                                    </div>
                                @endif

                                {{-- Word safety-number (D-08): 6 words, two rows of 3, mono uppercase --}}
                                @if ($device['safety_number_words'] !== '')
                                    @php
                                        $words = preg_split('/\s+/', trim((string) $device['safety_number_words'])) ?: [];
                                        $rowOne = array_slice($words, 0, 3);
                                        $rowTwo = array_slice($words, 3, 3);
                                    @endphp
                                    <div
                                        class="space-y-2"
                                        aria-label="Safety number words: {{ strtoupper(implode(' ', $words)) }}"
                                    >
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($rowOne as $word)
                                                <span class="rounded bg-slate-100 px-2 py-1 font-mono text-sm uppercase tracking-wide text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $word }}</span>
                                            @endforeach
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($rowTwo as $word)
                                                <span class="rounded bg-slate-100 px-2 py-1 font-mono text-sm uppercase tracking-wide text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $word }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Paired-at meta --}}
                                @if ($device['paired_at'] !== '')
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        Paired {{ \Carbon\CarbonImmutable::parse($device['paired_at'])->format('j M Y') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- Pair a new device (D-11) — dispatch a Livewire event the modal
                 component listens for; it owns its own open state so the hosting
                 <flux:modal> sees a real false→true transition. --}}
            <button
                type="button"
                wire:click="$dispatch('open-pairing-modal')"
                class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                       hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
            >
                Pair a new device
            </button>

            {{-- ===== D-06 (Phase 13): per-peer sync status surface ===== --}}
            @livewire('sync.sync-status-section', key('sync-status-section'))

            {{-- ===== D-01 (Phase 13): relay endpoint URL (default none) ===== --}}
            <div class="space-y-3 pt-2">
                <div>
                    <label
                        for="relay-endpoint-url"
                        class="block text-sm font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Relay endpoint
                    </label>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Optional. When set, offline devices sync via this relay. Leave empty for LAN&#8209;direct only.
                    </p>
                </div>

                <div class="flex gap-2">
                    <input
                        id="relay-endpoint-url"
                        type="url"
                        wire:model="relayEndpointUrl"
                        placeholder="https://relay.example.com"
                        aria-label="Relay endpoint URL"
                        class="block min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900
                               placeholder:text-slate-400
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500
                               dark:focus-visible:ring-slate-100"
                        data-testid="relay-endpoint-input"
                    />
                    <button
                        type="button"
                        wire:click="saveRelayEndpoint"
                        class="min-h-[44px] flex-shrink-0 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                        data-testid="relay-endpoint-save"
                    >
                        Save
                    </button>
                </div>

                {{-- Non-HTTPS warning (T-13-08 / Pitfall 6) --}}
                @if ($relayIsInsecure)
                    <div
                        class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700
                               dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                        role="alert"
                        data-testid="relay-insecure-warning"
                    >
                        <span aria-hidden="true" class="mt-0.5 flex-shrink-0">⚠</span>
                        <span>
                            This relay endpoint uses plain HTTP. While the relay never decrypts your data,
                            an insecure connection exposes encrypted sizes and timing to network observers.
                            Use an <strong>https://</strong> endpoint for best privacy.
                        </span>
                    </div>
                @endif

                {{-- Relay save flash message --}}
                @if ($relayFlashMessage !== '')
                    <p
                        class="text-xs text-slate-600 dark:text-slate-400"
                        role="status"
                        data-testid="relay-flash"
                    >{{ $relayFlashMessage }}</p>
                @endif
            </div>
        </div>
    @endif

    {{-- ===== Pairing-flow modal (D-11) — the component owns its own flux:modal.
         Rendered unconditionally so the modal's wire:model="open" sees a real
         false→true transition when "Pair a new device" dispatches
         open-pairing-modal (a fresh already-true mount never triggers Flux). ===== --}}
    @livewire('sync.pairing-flow-modal', key('pairing-flow-modal'))
</div>
