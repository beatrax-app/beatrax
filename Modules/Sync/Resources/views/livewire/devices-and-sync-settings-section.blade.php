{{--
    Devices & Sync settings section — UI-SPEC Surface A (Phase 12, D-02/D-09/D-10/D-11/D-12).
    Mounted into the Core settings page via @livewire('sync.devices-and-sync-settings-section').

    Decisions enforced here:
      - D-02: enable-sync is gated on an app-lock being configured. With no
        app-lock the toggle is dimmed/disabled and an info notice with a
        "Go to App lock" link (-> #app-lock) is shown.
      - D-09: each device row shows an inline-renamable name (hover-reveal pencil).
      - D-10: NO revoke / remove action — view / rename / verify only.
      - D-11: "Pair a new device" opens the pairing-flow modal.
      - D-12: identity is generated only on enable-sync; until then no device list.

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

            {{-- Pair a new device (D-11) --}}
            <button
                type="button"
                wire:click="openPairingModal"
                class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                       hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
            >
                Pair a new device
            </button>
        </div>
    @endif

    {{-- ===== Pairing-flow modal (D-11) ===== --}}
    @if ($pairingModalOpen)
        <flux:modal wire:model="pairingModalOpen" class="md:max-w-md">
            <div class="space-y-4 p-6">
                @livewire('sync.pairing-flow-modal')
            </div>
        </flux:modal>
    @endif
</div>
