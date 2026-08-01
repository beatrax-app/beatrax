{{--
    Sync-status surface — D-06 (Phase 13).
    Mounted inside the "Devices & Sync" settings section via
    @livewire('sync.sync-status-section') in devices-and-sync-settings-section.blade.php.

    Renders:
      - Overall "all devices up to date · synced Nm ago" summary line.
      - Per-peer list: online/offline dot + last-seen relative time.
      - Explicit error states: Relay unreachable / Can't reach peer / Handshake-verify failed.
      - Offline state when peers exist but none are active.

    Aesthetic: calm slate per sketch-findings-beatrax — emerald-600 = OK,
    amber-700 = warn, rose-700 = fail, slate-500 = muted / offline.
    No new design primitives — composes existing Flux / Tailwind tokens.
--}}

@use('Modules\Core\Public\Support\Lang')
<div class="space-y-4" data-testid="sync-status-section">

    {{-- ===== Overall status line ===== --}}
    @if ($overallStatus === 'unknown')
        {{-- No sync sessions yet — calm empty state --}}
        <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400" data-testid="sync-status-overall">
            <span class="inline-block h-2 w-2 rounded-full bg-slate-300 dark:bg-slate-600" aria-hidden="true"></span>
            {{ Lang::get('sync::status.no_devices') }}
        </div>

    @elseif ($overallStatus === 'error')
        <div
            class="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700
                   dark:border-rose-900 dark:bg-rose-950 dark:text-rose-300"
            role="alert"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-rose-600 dark:bg-rose-500" aria-hidden="true"></span>
            {{ Lang::get('sync::status.error') }}
        </div>

    @elseif ($overallStatus === 'syncing')
        <div
            class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700
                   dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 animate-pulse rounded-full bg-blue-500 dark:bg-blue-400" aria-hidden="true"></span>
            {{ Lang::get('sync::status.syncing') }}
        </div>

    @elseif ($overallStatus === 'offline')
        <div
            class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700
                   dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-amber-500 dark:bg-amber-400" aria-hidden="true"></span>
            {{ Lang::get('sync::status.offline') }}
        </div>

    @else
        {{-- all_synced --}}
        <div
            class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700
                   dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"
            data-testid="sync-status-overall"
        >
            <span class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500 dark:bg-emerald-400" aria-hidden="true"></span>
            {{ Lang::get('sync::status.all_synced') }}
            @if ($lastSyncedHuman !== null)
                <span class="text-emerald-600 dark:text-emerald-400">&middot; {{ Lang::get('sync::status.synced') }} {{ $lastSyncedHuman }}</span>
            @endif
        </div>
    @endif

    {{-- ===== Per-peer list ===== --}}
    @if (count($peerStatuses) > 0)
        <ul class="divide-y divide-slate-100 dark:divide-slate-800" data-testid="sync-peer-list">
            @foreach ($peerStatuses as $peer)
                @php
                    $status        = is_string($peer['status'] ?? null) ? $peer['status'] : '';
                    $peerDeviceId  = is_string($peer['peer_device_id'] ?? null) ? $peer['peer_device_id'] : '';
                    $lastSeenHuman = is_string($peer['last_seen_human'] ?? null) ? $peer['last_seen_human'] : null;
                    $errorLabel    = is_string($peer['error_label'] ?? null) ? $peer['error_label'] : '';
                    $isActive      = in_array($status, ['active', 'connecting', 'handshaking'], true);
                    $isFailed      = $status === 'failed';
                @endphp
                <li class="flex items-start justify-between gap-4 py-3" data-testid="sync-peer-row" wire:key="peer-{{ $peerDeviceId }}">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        {{-- Status dot --}}
                        @if ($isFailed)
                            <span
                                class="mt-0.5 inline-block h-2 w-2 flex-shrink-0 rounded-full bg-rose-500 dark:bg-rose-400"
                                aria-label="{{ Lang::get('sync::status.dot_error') }}"
                            ></span>
                        @elseif ($isActive)
                            <span
                                class="mt-0.5 inline-block h-2 w-2 flex-shrink-0 animate-pulse rounded-full bg-blue-500 dark:bg-blue-400"
                                aria-label="{{ Lang::get('sync::status.dot_online') }}"
                            ></span>
                        @else
                            <span
                                class="mt-0.5 inline-block h-2 w-2 flex-shrink-0 rounded-full bg-slate-300 dark:bg-slate-600"
                                aria-label="{{ Lang::get('sync::status.dot_offline') }}"
                            ></span>
                        @endif

                        <div class="min-w-0">
                            {{-- Device ID in monospace (matches safety-number presentation convention) --}}
                            <p
                                class="truncate font-mono text-sm text-slate-700 dark:text-slate-300"
                                style="font-family: 'JetBrains Mono', 'Fira Mono', monospace; font-size: 12px;"
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

                    {{-- Last-seen time --}}
                    <div class="flex-shrink-0 text-right">
                        @if ($lastSeenHuman !== null)
                            <span
                                class="text-xs tabular-nums text-slate-500 dark:text-slate-400"
                                style="font-feature-settings: 'tnum';"
                                data-testid="peer-last-seen"
                            >{{ $lastSeenHuman }}</span>
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-500" data-testid="peer-last-seen">{{ Lang::get('sync::status.never') }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

</div>
