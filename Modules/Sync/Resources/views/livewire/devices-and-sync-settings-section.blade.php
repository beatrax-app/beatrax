{{--
    Devices & Sync settings section — UI-SPEC Surface A. Owns the
    enable-sync toggle, the paired-device list, the per-peer sync-status
    surface, the relay endpoint URL field, and the at-rest encryption
    status row with its enable and device-revocation modals.

    Mounted into the Core settings page via @livewire('sync.devices-and-sync-settings-section').

    Decisions enforced here:
      - Enable-sync is gated on an app-lock being configured. With no
        app-lock the toggle is dimmed/disabled and an info notice with a
        "Go to App lock" link (-> #app-lock) is shown.
      - Each device row shows an inline-renamable name (hover-reveal pencil).
      - "Pair a new device" opens the pairing-flow modal.
      - Identity is generated only on enable-sync; until then no device list.
      - Per-peer sync status + overall "up to date · synced Nm ago" is
        rendered via @livewire('sync.sync-status-section') when sync is on.
      - Relay endpoint URL field (default none = LAN-direct); a non-HTTPS
        URL shows an insecure-connection warning. Writes are gated on
        app-lock.
      - Encryption is mandatory once synced and optional on a single
        device: the status row shows the decline-able offer ONLY when sync
        is off; a synced-but-not-yet-encrypted device shows the transient
        "Securing your data…" auto-activation state with NO CTA and NO decline.
      - Honest messaging: the enable-encryption confirm step discloses that
        amounts stay plaintext/aggregatable and the search index keeps a
        plaintext shadow copy.
      - Per-row remove: each non-self row gets a "Remove" action opening
        the honest revocation modal (rotation stops future updates; it
        cannot erase data already on that device).
      - Absent-copy rule: no "remote wipe" / "the other device's data is
        deleted" / "your data is now safe from that device" language
        anywhere in this file.

    Copywriting + tokens follow UI-SPEC; calm-slate (sketch-findings-beatrax),
    weights 400/600 only, min-h-[44px] on all buttons, JetBrains Mono for the
    safety-number identifiers.
--}}

@use('Modules\Core\Public\Support\Lang')
@use('Modules\Sync\Internal\Crypto\EncryptionSetupStep')
@use('Modules\Sync\Internal\OpLog\SyncBacklogState')
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('sync::devices.heading') }}</h2>

    @if ($flashMessage !== '')
        <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
    @endif

    {{-- ===== Enable-sync toggle row (app-lock gate) ===== --}}
    <x-core::setting-row
        :label="Lang::get('sync::devices.enable_sync')"
        :description="Lang::get('sync::devices.enable_sync_help')"
    >
        {{-- Blade does not compile @class / @disabled between the attributes of
             an <x-…> tag, so the app-lock gate is spelled as bound expressions.
             The dimming classes ADD to the track the component builds. --}}
        <x-core::switch
            :on="$syncEnabled"
            :label="Lang::get('sync::devices.enable_sync')"
            wire:click="{{ $syncEnabled ? '' : ($appLockConfigured ? 'enableSync' : '') }}"
            :class="! $appLockConfigured && ! $syncEnabled ? 'opacity-50 cursor-not-allowed pointer-events-none' : ''"
            :disabled="! $appLockConfigured && ! $syncEnabled"
        />
    </x-core::setting-row>

    {{-- App-lock gate notice (shown when sync is off and no app-lock is set) --}}
    @if (! $syncEnabled && ! $appLockConfigured)
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300" role="note">
            <p>{{ Lang::get('sync::devices.app_lock_notice') }}</p>
            <a
                href="#app-lock"
                class="tap-link mt-1 inline-block text-sm font-semibold text-blue-700 underline-offset-2 hover:underline dark:text-blue-300"
            >
                {{ Lang::get('sync::devices.go_to_app_lock') }}
            </a>
        </div>
    @endif

    {{-- A key-file this device holds and cannot open. Named, because the
         ordinary off-state next to it would read as "sync was never turned
         on" — and every action behind that reading fails. --}}
    @if ($identityUnreadable)
        <x-core::alert tone="warning" role="alert" data-testid="identity-unreadable-notice">
            <p>{{ Lang::get('sync::devices.identity_unreadable') }}</p>

            {{-- The replacement is offered only with no self row registered:
                 with one, peers were told about the old identity and retiring
                 it is more than this button does. --}}
            @if (! $syncEnabled)
                <p class="mt-2">{{ Lang::get('sync::devices.identity_unreadable_replace_help') }}</p>
                <x-core::neutral-button
                    class="mt-3 min-h-[44px]"
                    wire:click="replaceUnreadableIdentity"
                    data-testid="replace-unreadable-identity"
                >
                    {{ Lang::get('sync::devices.identity_unreadable_replace') }}
                </x-core::neutral-button>
            @endif
        </x-core::alert>
    @endif

    {{-- Data that arrived while nobody was looking. Deferred clears itself on
         the next request, so the notice exists only so a screen that is briefly
         behind does not read as sync being broken; AwaitingKey does not clear
         itself and must not be given the same words. --}}
    @if ($backlogState !== SyncBacklogState::None)
        <x-core::alert
            :tone="$backlogState->clearsWithoutHelp() ? 'info' : 'warning'"
            role="status"
            data-testid="sync-backlog-notice"
        >
            <p class="font-semibold">{{ Lang::get('sync::devices.backlog_heading') }}</p>
            <p class="mt-1">
                {{ $backlogState->clearsWithoutHelp()
                    ? Lang::get('sync::devices.backlog_deferred')
                    : Lang::get('sync::devices.backlog_awaiting_key') }}
            </p>
        </x-core::alert>
    @endif

    {{-- ===== Surface A: encryption status row =====
         Shown alongside the sync controls regardless of sync state:
         single-device sync-off users see the optional decline-able offer;
         a synced-but-not-yet-encrypted device sees the mandatory transient
         "Securing your data…" state (no CTA, no decline); once ON, the
         status row replaces both. --}}
    @if ($appLockConfigured)
        <div class="space-y-3" data-testid="encryption-status-row">
            @if ($encryptionOn)
                <x-core::setting-row
                    :label="Lang::get('sync::devices.encrypted_at_rest')"
                    :description="Lang::get('sync::devices.encrypted_at_rest_scope')"
                >
                    <x-core::status-pill tone="positive">{{ Lang::get('sync::devices.on') }}</x-core::status-pill>
                </x-core::setting-row>
            @elseif ($syncEnabled)
                {{-- Mandatory once synced: transient auto-activation. NO CTA, NO decline. --}}
                <x-core::alert
                    tone="warning"
                    data-testid="encryption-securing-notice"
                >
                    <p aria-live="polite" class="font-semibold">{{ Lang::get('sync::devices.securing') }}</p>
                    <x-core::progress-bar
                        class="mt-2"
                        :value="$encryptionProgress"
                        :label="Lang::get('sync::devices.encryption_progress_aria')"
                    />
                    <p class="mt-1 text-xs">{{ Lang::get('sync::devices.do_not_close') }}</p>
                </x-core::alert>
            @else
                {{-- Single-device (sync off) optional offer. --}}
                <div
                    class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300"
                    role="note"
                    data-testid="encryption-offer-notice"
                >
                    <p>{{ Lang::get('sync::devices.not_encrypted_offer') }}</p>
                    <x-core::neutral-button
                        class="mt-3 min-h-[44px]"
                        wire:click="showEnableEncryptionModal"
                        data-testid="enable-encryption-cta"
                    >
                        {{ Lang::get('sync::devices.enable_encryption') }}
                    </x-core::neutral-button>
                </div>
            @endif
        </div>
    @endif

    {{-- ===== Device list + Pair CTA (only when sync is ON) ===== --}}
    @if ($syncEnabled)
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('sync::devices.your_devices') }}</h3>

            {{-- A handshake that reached the safety-word comparison and is
                 waiting on this device. The modal already resumes one; without
                 this the only way back in was a button reading "Pair a new
                 device", and the token expires while the reader looks for it. --}}
            @if ($pairingWaitingOnPeer !== '')
                <x-core::alert tone="info" data-testid="pairing-waiting-notice">
                    <p class="font-semibold">{{ Lang::get('sync::devices.pairing_waiting', ['name' => $pairingWaitingOnPeer]) }}</p>
                    <p class="mt-1">{{ Lang::get('sync::devices.pairing_waiting_help') }}</p>

                    {{-- The same render that held this ceremony open past the
                         app-lock timeout says so. A silent extension is a lock
                         policy quietly overridden; a stated one is a bounded
                         exception the reader can end by cancelling. --}}
                    <p
                        class="mt-2 border-l-2 border-current pl-2 font-semibold"
                        role="note"
                        data-testid="pairing-lock-override-notice"
                    >
                        {{ Lang::get('sync::devices.pairing_waiting_lock_override') }}
                    </p>

                    <x-core::neutral-button
                        class="mt-3 min-h-[44px]"
                        wire:click="$dispatch('open-pairing-modal')"
                        data-testid="pairing-waiting-resume"
                    >
                        {{ Lang::get('sync::devices.pairing_waiting_resume') }}
                    </x-core::neutral-button>
                </x-core::alert>
            @endif

            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach ($devices as $device)
                    <li class="py-4" wire:key="device-{{ $device['id'] }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                {{-- Name + inline rename --}}
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
                                            placeholder="{{ Lang::get('sync::devices.device_name') }}"
                                            aria-label="{{ Lang::get('sync::devices.device_name') }}"
                                            class="block w-full max-w-xs rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900
                                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                                        />
                                        <x-core::neutral-button
                                            size="sm"
                                            class="min-h-[44px]"
                                            wire:click="renameDevice"
                                        >
                                            {{ Lang::get('sync::devices.save') }}
                                        </x-core::neutral-button>
                                    </div>
                                @else
                                    <div class="group flex items-center gap-2">
                                        <span class="text-sm text-slate-900 dark:text-slate-100">{{ $device['name'] }}</span>

                                        @if (! ($device['removed'] ?? false))
                                            {{-- The reveal-on-hover classes stay: the pencil is
                                                 deliberately quiet until the row is hovered or
                                                 focused. Only the bespoke chrome is dropped. --}}
                                            <x-core::emoji-action
                                                :label="Lang::get('sync::devices.rename_device')"
                                                :caption="Lang::get('sync::devices.rename_device_caption')"
                                                class="opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100"
                                                wire:click="startRename('{{ $device['id'] }}')"
                                            >✏️</x-core::emoji-action>
                                        @endif

                                        @if ($device['is_self'])
                                            <x-core::status-pill tone="warning">{{ Lang::get('sync::devices.this_device') }}</x-core::status-pill>
                                        @endif

                                        {{-- Surface C: "Removed" replaces Confirmed/Awaiting. --}}
                                        @if ($device['removed'] ?? false)
                                            <x-core::status-pill
                                                tone="danger"
                                                data-testid="removed-badge-{{ $device['id'] }}"
                                            >{{ Lang::get('sync::devices.removed') }}</x-core::status-pill>
                                        @elseif ($device['confirmed'])
                                            <x-core::status-pill tone="positive">{{ Lang::get('sync::devices.confirmed') }}</x-core::status-pill>
                                        @else
                                            <x-core::status-pill tone="warning">{{ Lang::get('sync::devices.awaiting_confirmation') }}</x-core::status-pill>
                                        @endif
                                    </div>
                                @endif

                                {{-- Word safety-number: 6 words, two rows of 3, mono uppercase --}}
                                @if ($device['safety_number_words'] !== '')
                                    @php
                                        $words = preg_split('/\s+/', trim((string) $device['safety_number_words'])) ?: [];
                                        $rowOne = array_slice($words, 0, 3);
                                        $rowTwo = array_slice($words, 3, 3);
                                    @endphp
                                    <div
                                        class="space-y-2"
                                        role="group"
                                        aria-label="{{ Lang::get('sync::devices.safety_number_words') }} {{ strtoupper(implode(' ', $words)) }}"
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
                                        {{ Lang::get('sync::devices.paired') }} {{ \Carbon\CarbonImmutable::parse($device['paired_at'])->translatedFormat('j M Y') }}
                                    </p>
                                @endif
                            </div>

                            {{-- Surface C: per-row Remove action.
                                 Non-self, not-already-removed rows only. --}}
                            @if (! ($device['is_self'] ?? false) && ! ($device['removed'] ?? false))
                                <button
                                    type="button"
                                    wire:click="startRemove('{{ $device['id'] }}')"
                                    aria-label="{{ Lang::get('sync::devices.remove_aria', ['name' => $device['name']]) }}"
                                    class="min-h-[44px] flex-shrink-0 py-3 text-sm font-medium text-rose-600
                                           hover:text-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 rounded
                                           dark:text-rose-400 dark:hover:text-rose-300"
                                    data-testid="remove-device-{{ $device['id'] }}"
                                >
                                    {{ Lang::get('sync::devices.remove') }}
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- Pair a new device — dispatch a Livewire event the modal
                 component listens for; it owns its own open state so the hosting
                 <flux:modal> sees a real false→true transition. --}}
            <x-core::neutral-button
                block="full"
                class="min-h-[44px]"
                wire:click="$dispatch('open-pairing-modal')"
            >
                {{ Lang::get('sync::devices.pair_new_device') }}
            </x-core::neutral-button>

            {{-- The per-peer status surface used to render here as well
                 as at the top of the Data & Devices page, so that page carried
                 two identical status banners and two peer lists. This section
                 is only ever mounted there, so the copy above it is the one
                 that survives — status at the top, management here. --}}

            {{-- ===== Relay endpoint URL (default none) ===== --}}
            <div class="space-y-3 pt-2">
                <div>
                    <label
                        for="relay-endpoint-url"
                        class="block text-sm font-semibold text-slate-900 dark:text-slate-100"
                    >
                        {{ Lang::get('sync::devices.relay_endpoint') }}
                    </label>
                    {{-- App-static copy carrying one non-breaking hyphen entity. --}}
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {!! Lang::get($onPhone ? 'sync::devices.relay_endpoint_help_phone' : 'sync::devices.relay_endpoint_help') !!}
                    </p>
                </div>

                <div class="flex gap-2">
                    <input
                        id="relay-endpoint-url"
                        type="url"
                        wire:model="relayEndpointUrl"
                        placeholder="https://relay.example.com"
                        aria-label="{{ Lang::get('sync::devices.relay_endpoint_aria') }}"
                        class="block min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900
                               placeholder:text-slate-500 dark:placeholder:text-slate-400
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-400
                               dark:focus-visible:ring-slate-100"
                        data-testid="relay-endpoint-input"
                    />
                    <x-core::neutral-button
                        class="min-h-[44px] flex-shrink-0"
                        wire:click="saveRelayEndpoint"
                        data-testid="relay-endpoint-save"
                    >
                        {{ Lang::get('sync::devices.save') }}
                    </x-core::neutral-button>
                </div>

                {{-- Non-HTTPS warning --}}
                @if ($relayIsInsecure)
                    <x-core::alert
                        tone="warning"
                        class="flex items-start gap-2"
                        role="alert"
                        data-testid="relay-insecure-warning"
                    >
                        <span aria-hidden="true" class="mt-0.5 flex-shrink-0">⚠</span>
                        <span>
                            {!! Lang::get('sync::devices.relay_insecure_warning') !!}
                        </span>
                    </x-core::alert>
                @endif

                {{-- Relay save flash message --}}
                @if ($relayFlashMessage !== '')
                    <p
                        class="text-xs text-slate-600 dark:text-slate-400"
                        aria-live="polite" aria-atomic="true"
                        data-testid="relay-flash"
                    >{{ $relayFlashMessage }}</p>
                @endif
            </div>
        </div>
    @endif

    {{-- ===== Surface B: enable-encryption modal (single-device
         optional-offer path only). Confirm / progress / done /
         error inner states share one flux:modal keyed on the step. ===== --}}
    @if ($showEncryptionModal)
        <flux:modal wire:model="showEncryptionModal" class="md:max-w-sm" data-testid="enable-encryption-modal">
            <div class="space-y-4 p-6">
                @if ($encryptionModalStep === EncryptionSetupStep::Confirm)
                    <x-core::section-heading :title="Lang::get('sync::devices.enable_at_rest')" :level="3" />
                    <p class="text-sm text-slate-700 dark:text-slate-300">
                        {{ Lang::get('sync::devices.enable_at_rest_body') }}
                    </p>
                    <x-core::alert tone="danger" role="alert">
                        {{ Lang::get('sync::devices.no_recovery_warning') }}
                    </x-core::alert>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ Lang::get('sync::devices.recover_help') }}
                    </p>
                    {{-- Honest disclosure, pinned by DevicesAndSyncEncryptionUiTest
                         — do not remove or soften this copy.

                         Deliberately not x-core::alert: it is the least urgent
                         block in a narrow modal, and an alert put a second
                         bordered box under the rose one and raised two long
                         sentences of caveat to the weight of the body copy. It
                         belongs in the register of the help line above it. --}}
                    <div class="text-xs text-slate-500 dark:text-slate-400">
                        <p>{{ Lang::get('sync::devices.amounts_plaintext') }}</p>
                        <p class="mt-1">{{ Lang::get('sync::devices.search_plaintext') }}</p>
                        <p class="mt-1">{{ Lang::get('sync::devices.app_lock_permanent') }}</p>
                    </div>
                    <div class="flex gap-3">
                        <x-core::neutral-button
                            block="flex"
                            class="min-h-[44px]"
                            wire:click="enableEncryption"
                            wire:loading.attr="disabled"
                            wire:target="enableEncryption"
                            data-testid="confirm-enable-encryption"
                        >
                            {{ Lang::get('sync::devices.enable_encryption') }}
                        </x-core::neutral-button>
                        <x-core::secondary-button
                            block="flex"
                            class="min-h-[44px]"
                            wire:click="declineEncryption"
                            data-testid="decline-encryption"
                        >
                            {{ Lang::get('sync::devices.keep_unencrypted') }}
                        </x-core::secondary-button>
                    </div>
                @elseif ($encryptionModalStep === EncryptionSetupStep::Progress)
                    <div wire:poll.750ms.keep-alive="pollEncryptionProgress">
                        <x-core::section-heading :level="3" aria-live="polite" :title="Lang::get('sync::devices.securing')" />
                        <x-core::progress-bar
                            class="mt-4"
                            :value="$encryptionProgress"
                            :label="Lang::get('sync::devices.encryption_progress_aria')"
                        />
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('sync::devices.do_not_close') }}</p>
                    </div>
                @elseif ($encryptionModalStep === EncryptionSetupStep::Done)
                    <div class="space-y-3 text-center">
                        <svg class="mx-auto h-6 w-6 text-emerald-700 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100" aria-live="polite" aria-atomic="true">{{ Lang::get('sync::devices.encryption_enabled') }}</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('sync::devices.encryption_enabled_scope') }}</p>
                        <x-core::neutral-button
                            block="full"
                            class="min-h-[44px]"
                            wire:click="closeEncryptionModal"
                            data-testid="encryption-done"
                        >
                            {{ Lang::get('sync::devices.done_encryption_enabled') }}
                        </x-core::neutral-button>
                    </div>
                @else
                    <div class="space-y-3 text-center">
                        <svg class="mx-auto h-6 w-6 text-rose-600 dark:text-rose-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                        <x-core::section-heading :level="3" aria-live="polite" aria-atomic="true" :title="Lang::get('sync::devices.encryption_failed')" />
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('sync::devices.encryption_failed_body') }}</p>
                        <x-core::secondary-button
                            block="full"
                            class="min-h-[44px]"
                            wire:click="closeEncryptionModal"
                            data-testid="encryption-error-close"
                        >
                            {{ Lang::get('sync::devices.close_no_changes') }}
                        </x-core::secondary-button>
                    </div>
                @endif
            </div>
        </flux:modal>
    @endif

    {{-- ===== Surface D: device revocation modal.
         Honest warning: rotation stops FUTURE updates only; it cannot erase
         data already on the removed device, and the revoke half is local to
         this device. No "remote wipe" language. ===== --}}
    @if ($showRemoveModal && $removingDeviceId !== null)
        <flux:modal wire:model="showRemoveModal" class="md:max-w-sm" data-testid="revoke-device-modal">
            <div class="space-y-4 p-6">
                <div>
                    <x-core::section-heading :title="Lang::get('sync::devices.remove_this_device')" :level="3" />
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('sync::devices.removing') }} {{ $this->currentNameFor($removingDeviceId) }}</p>
                </div>

                <x-core::alert tone="warning" role="note">
                    <p>{{ Lang::get('sync::devices.remove_rotates_key') }}</p>
                    <p class="mt-1">{{ Lang::get('sync::devices.remove_cannot_erase') }}</p>
                    @if ($this->removalLeavesAnotherDeviceHolding())
                        <p class="mt-1">{{ Lang::get('sync::devices.remove_is_local') }}</p>
                    @endif
                </x-core::alert>

                <div class="flex gap-3" wire:loading.remove wire:target="removeDevice">
                    <button
                        type="button"
                        wire:click="removeDevice"
                        class="flex-1 min-h-[44px] rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                        data-testid="confirm-remove-device"
                    >
                        {{ Lang::get('sync::devices.remove_device') }}
                    </button>
                    <x-core::secondary-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="cancelRemove"
                        data-testid="cancel-remove-device"
                    >
                        {{ Lang::get('sync::devices.keep_device') }}
                    </x-core::secondary-button>
                </div>

                <div
                    wire:loading
                    wire:target="removeDevice"
                    class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"
                    aria-live="polite" aria-atomic="true"
                >
                    <x-core::spinner />
                    <span>{{ Lang::get('sync::devices.rotating_key') }}</span>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- ===== Pairing-flow modal — the component owns its own flux:modal.
         Rendered unconditionally so the modal's wire:model="open" sees a real
         false→true transition when "Pair a new device" dispatches
         open-pairing-modal (a fresh already-true mount never triggers Flux). ===== --}}
    @livewire('sync.pairing-flow-modal', key('pairing-flow-modal'))
</div>
