{{--
    Sync-status surface.
    Mounted inside the "Devices & Sync" settings section via
    @livewire('sync.sync-status-section') in devices-and-sync-settings-section.blade.php.

    Renders:
      - Overall "all devices up to date · synced Nm ago" summary line.
      - Per-peer list: online/offline dot + last-seen relative time.
      - Explicit error states: Relay unreachable / Can't reach peer / Handshake-verify failed.
      - Offline state when a peer cannot be reached.
      - Behind state when this device holds changes no session has carried.
      - Withheld state when a peer is holding entries this device cannot verify.

    Aesthetic: calm slate per sketch-findings-beatrax — emerald-600 = OK,
    amber-700 = warn, rose-700 = fail, slate-500 = muted / offline.
    No new design primitives — composes existing Flux / Tailwind tokens.
--}}

@use('Illuminate\Support\Js')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Sync\Public\Enums\SyncOverallStatus')
<div class="space-y-4" data-testid="sync-status-section">

    {{-- ===== Overall status line =====
         One branch per case and no @else: a case that fell through used to be
         drawn as "All devices up to date", so the state the vocabulary could
         not name was reported under the one sentence it must never borrow. --}}
    @if ($overall === SyncOverallStatus::Unknown)
        {{-- No sync_sessions rows yet. Not the same as having no device: the
             list below this line may well name two paired ones. --}}
        <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400" data-testid="sync-status-overall">
            <span class="inline-block h-2 w-2 rounded-full bg-slate-500 dark:bg-slate-400" aria-hidden="true"></span>
            {{ Lang::get($overall->labelKey()) }}
        </div>

    @elseif ($overall === SyncOverallStatus::Error)
        <x-core::alert
            tone="danger"
            class="flex items-center gap-2"
            role="alert"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-rose-600 dark:bg-rose-500" aria-hidden="true"></span>
            {{ Lang::get($overall->labelKey()) }}
        </x-core::alert>

    @elseif ($overall === SyncOverallStatus::Syncing)
        <div
            class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700
                   dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 animate-pulse rounded-full bg-blue-500 dark:bg-blue-400" aria-hidden="true"></span>
            {{ Lang::get($overall->labelKey()) }}
        </div>

    @elseif ($overall === SyncOverallStatus::Offline)
        <x-core::alert
            tone="warning"
            class="flex items-center gap-2"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-amber-500 dark:bg-amber-400" aria-hidden="true"></span>
            {{ Lang::get($overall->labelKey()) }}
        </x-core::alert>

    @elseif ($overall === SyncOverallStatus::Withheld)
        {{-- Info for the reason Behind is: a peer holding history back for an
             author this device cannot check is the exchange working as
             designed. What it must not be is invisible, because the reader is
             the only one who can end it and the detail sits further down. --}}
        <x-core::alert
            tone="info"
            class="flex items-center gap-2"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-sky-500 dark:bg-sky-400" aria-hidden="true"></span>
            {{ Lang::get($overall->labelKey()) }}
            @if ($lastSyncedHuman !== null)
                <span>&middot; {{ Lang::get('sync::status.synced') }} {{ $lastSyncedHuman }}</span>
            @endif
        </x-core::alert>

    @elseif ($overall === SyncOverallStatus::Behind)
        {{-- Info, not warning: nothing has failed and no peer is unreachable.
             What is true is that this device holds changes no peer has, which
             is the one thing "all devices up to date" must not be said over. --}}
        <x-core::alert
            tone="info"
            class="flex items-center gap-2"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-sky-500 dark:bg-sky-400" aria-hidden="true"></span>
            {{ Lang::get($overall->labelKey()) }}
            @if ($lastSyncedHuman !== null)
                <span>&middot; {{ Lang::get('sync::status.synced') }} {{ $lastSyncedHuman }}</span>
            @endif
        </x-core::alert>

    @elseif ($overall === SyncOverallStatus::AllSynced)
        <x-core::alert
            tone="positive"
            class="flex items-center gap-2"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500 dark:bg-emerald-400" aria-hidden="true"></span>
            {{ Lang::get($overall->labelKey()) }}
            @if ($lastSyncedHuman !== null)
                <span class="text-emerald-700 dark:text-emerald-400">&middot; {{ Lang::get('sync::status.synced') }} {{ $lastSyncedHuman }}</span>
            @endif
        </x-core::alert>
    @endif

    {{-- ===== Per-peer list ===== --}}
    @if (count($peerStatuses) > 0)
        <ul class="divide-y divide-slate-100 dark:divide-slate-800" data-testid="sync-peer-list">
            @foreach ($peerStatuses as $peer)
                @php
                    $peerDeviceId  = is_string($peer['peer_device_id'] ?? null) ? $peer['peer_device_id'] : '';
                    $displayName   = is_string($peer['display_name'] ?? null) ? $peer['display_name'] : $peerDeviceId;
                    $isKnownPeer   = ($peer['is_known'] ?? false) === true;
                    $lastSeenHuman = is_string($peer['last_seen_human'] ?? null) ? $peer['last_seen_human'] : null;
                    $errorLabel    = is_string($peer['error_label'] ?? null) ? $peer['error_label'] : '';
                    $isActive      = ($peer['is_live'] ?? false) === true;
                    $isFailed      = ($peer['is_failed'] ?? false) === true;
                @endphp
                <li class="flex items-start justify-between gap-4 py-3" data-testid="sync-peer-row" wire:key="peer-{{ $peerDeviceId }}">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        {{-- Status dot --}}
                        @if ($isFailed)
                            <span
                                role="img"
                                class="mt-0.5 inline-block h-2 w-2 flex-shrink-0 rounded-full bg-rose-500 dark:bg-rose-400"
                                aria-label="{{ Lang::get('sync::status.dot_error') }}"
                            ></span>
                        @elseif ($isActive)
                            <span
                                role="img"
                                class="mt-0.5 inline-block h-2 w-2 flex-shrink-0 animate-pulse rounded-full bg-blue-500 dark:bg-blue-400"
                                aria-label="{{ Lang::get('sync::status.dot_online') }}"
                            ></span>
                        @else
                            <span
                                role="img"
                                class="mt-0.5 inline-block h-2 w-2 flex-shrink-0 rounded-full bg-slate-500 dark:bg-slate-400"
                                aria-label="{{ Lang::get('sync::status.dot_offline') }}"
                            ></span>
                        @endif

                        <div class="min-w-0">
                            {{-- The name leads because that is what identifies the
                                 machine to a person; the id stays underneath so
                                 what the app is actually keyed on is never hidden. --}}
                            <p @class([
                                'truncate text-sm',
                                'font-medium text-slate-900 dark:text-slate-100' => $isKnownPeer,
                                'text-slate-500 italic dark:text-slate-400' => ! $isKnownPeer,
                            ])>{{ $displayName }}</p>
                            <p
                                class="truncate font-mono text-slate-600 dark:text-slate-400"
                                style="font-family: ui-monospace, 'SF Mono', monospace; font-size: 11px;"
                                data-testid="peer-device-id"
                            >{{ $peerDeviceId }}</p>

                            {{-- Error label — only when failed --}}
                            @if ($isFailed && $errorLabel !== '')
                                <p
                                    class="mt-0.5 text-xs font-medium text-rose-600 dark:text-rose-400"
                                    data-testid="peer-error-label"
                                >{{ $errorLabel }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Time and action as one trailing column: the dismiss
                         control used to sit before the timestamp, so its
                         position tracked the length of each device id and no
                         two buttons lined up. --}}
                    <div class="flex flex-shrink-0 items-center gap-1">
                        <div class="text-right">
                            @if ($lastSeenHuman !== null)
                                <span
                                    class="text-xs tabular-nums text-slate-500 dark:text-slate-400"
                                    style="font-feature-settings: 'tnum';"
                                    data-testid="peer-last-seen"
                                >{{ $lastSeenHuman }}</span>
                            @else
                                <span class="text-xs text-slate-600 dark:text-slate-400" data-testid="peer-last-seen">{{ Lang::get('sync::status.never') }}</span>
                            @endif
                        </div>

                        {{-- A bin, not a cross: this deletes a stored record
                             rather than closing something. --}}
                        <x-core::emoji-action
                            :label="Lang::get('sync::status.dismiss_peer')"
                            :caption="Lang::get('sync::status.dismiss_peer_caption')"
                            tone="danger"
                            wire:click="dismissPeer({{ Js::from($peerDeviceId) }})"
                            data-testid="peer-dismiss"
                        >🗑️</x-core::emoji-action>
                    </div>
                </li>
            @endforeach
        </ul>

        <button
            type="button"
            wire:click="dismissStale"
            class="mt-3 text-xs font-medium text-slate-500 underline underline-offset-2 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-400 dark:hover:text-slate-200"
            data-testid="dismiss-stale-sessions"
        >
            {{ Lang::get('sync::status.dismiss_stale') }}
        </button>
    @endif

</div>
