@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
{{-- /inboxes page (D-124 / D-127).

     Renders the empty-state hero when no inboxes are connected and
     the connected-inboxes table-driven layout once at least one
     inbox exists. Plan 03 ships the hero + minimal table + Add-
     inbox card pair + Connect buttons; Plan 05 adds the backfill-
     window modal; Plan 07 adds the row actions + discovered-senders
     panel.

     All copy is locked verbatim against 06-UI-SPEC.md § Copywriting
     Contract. --}}

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-12">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::inboxes.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('email-scan::inboxes.intro') }}
        </p>
    </header>

    {{-- Read OAuth flash values from props the Livewire component
         pulled out of the session in mount(), not via the session()
         global helper. The DI-only invariant applies to Blade views
         too. --}}
    @if ($oauthCanceledMessage !== null)
        <div aria-live="polite" aria-atomic="true" class="mb-6 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-600 dark:bg-rose-950 dark:border-rose-800 dark:text-rose-200">
            {{ Lang::get('email-scan::inboxes.connection_canceled') }} {{ $oauthCanceledMessage }}
        </div>
    @endif

    @if ($oauthFailedMessage !== null)
        <div aria-live="polite" aria-atomic="true" class="mb-6 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-600 dark:bg-rose-950 dark:border-rose-800 dark:text-rose-200">
            {{ Lang::get('email-scan::inboxes.connection_failed') }} {{ $oauthFailedMessage }}
        </div>
    @endif

    @php
        $activeBackfills = collect($inboxes)->filter(fn ($i) => $i->backfillFetchedCount !== null)->values();
    @endphp

    @if ($activeBackfills->count() > 0)
        {{-- Backfill progress strip — visible only while at least one inbox has an
             active BackfillInboxJob. wire:poll.2s re-renders the section so the live
             count climbs without a full page reload; the strip disappears once every
             inbox has finished and backfill_progress has been cleared. --}}
        <section
            wire:poll.2s="refreshBackfillProgress"
            class="rounded-md border border-slate-200 bg-slate-50 p-4 space-y-2 mb-6 dark:bg-slate-900 dark:border-slate-700"
            aria-live="polite"
        >
            @foreach ($activeBackfills as $inbox)
                <div class="flex items-center justify-between text-xs text-slate-700 dark:text-slate-300">
                    <span>
                        {{ Lang::get('email-scan::inboxes.backfilling') }} {{ $inbox->provider === \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value ? 'Gmail' : 'Microsoft 365' }} ({{ $inbox->email }}):
                        <span style="font-variant-numeric: tabular-nums;">{{ Fmt::number((int) $inbox->backfillFetchedCount) }} / ~{{ Fmt::number((int) ($inbox->backfillTotalEstimated ?? 0)) }}</span>
                        {{ Lang::get('email-scan::inboxes.messages_suffix') }}
                    </span>
                </div>
            @endforeach
        </section>
    @endif

    @if (count($inboxes) === 0)
        {{-- Empty-state hero per UI-SPEC § Empty state hero (zero inboxes connected). --}}
        <section class="mx-auto max-w-md text-center mt-12">
            <h2 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::inboxes.connect_heading') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('email-scan::inboxes.connect_body') }}
            </p>
            <div class="mt-8 flex items-center justify-center gap-4">
                <button
                    type="button"
                    wire:click="openWizard('{{ \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value }}')"
                    class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >{{ Lang::get('email-scan::inboxes.connect_gmail') }}</button>
                <button
                    type="button"
                    wire:click="openWizard('{{ \Modules\EmailScan\Public\Enums\MailProvider::Microsoft->value }}')"
                    class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >{{ Lang::get('email-scan::inboxes.connect_microsoft') }}</button>
            </div>
            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('email-scan::inboxes.readonly_note') }}
            </p>
        </section>
    @else
        {{-- Connected-inboxes table — full row chrome with status badge
             matrix + Scan-Now / Reconnect / Window-edit actions
             (UI-SPEC § Connected Inboxes Table + § Status Badge Matrix). --}}
        <ul class="space-y-4">
            @foreach ($inboxes as $inbox)
                @php
                    $providerLabel = $inbox->provider === \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value ? 'Gmail' : 'Microsoft 365';
                    $windowText = $inbox->backfillWindowMonths === 1
                        ? Lang::get('email-scan::inboxes.month')
                        : Lang::get('email-scan::inboxes.months', ['count' => $inbox->backfillWindowMonths]);
                    $lastScanText = $inbox->lastScanAt === null
                        ? Lang::get('email-scan::inboxes.not_scanned_yet')
                        : Lang::get('email-scan::inboxes.last_scanned').' '.\Carbon\CarbonImmutable::instance($inbox->lastScanAt)->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, short: true);

                    // Status Badge Matrix — UI-SPEC § Status Badge Matrix.
                    // Six variants matching inbox_scan_state.status enum.
                    $badgeColor = [
                        'idle' => 'slate',
                        'backfilling' => 'sky',
                        'scanning' => 'sky',
                        'rate_limited' => 'amber',
                        'needs_reauth' => 'rose',
                        'error' => 'slate',
                    ][$inbox->status] ?? 'slate';

                    $badgeLabel = [
                        'idle' => Lang::get('email-scan::inboxes.badge.idle'),
                        'backfilling' => Lang::get('email-scan::inboxes.badge.backfilling'),
                        'scanning' => Lang::get('email-scan::inboxes.badge.scanning'),
                        'rate_limited' => Lang::get('email-scan::inboxes.badge.rate_limited'),
                        'needs_reauth' => Lang::get('email-scan::inboxes.badge.needs_reauth'),
                        'error' => Lang::get('email-scan::inboxes.badge.error'),
                    ][$inbox->status] ?? Lang::get('email-scan::inboxes.badge.idle');

                    $scanDisabled = in_array($inbox->status, [\Modules\EmailScan\Public\Enums\InboxScanStatus::Backfilling->value, \Modules\EmailScan\Public\Enums\InboxScanStatus::Scanning->value], strict: true);

                    // Inline retry-after detail for rate_limited rows.
                    $retryDetail = null;
                    if ($inbox->status === \Modules\EmailScan\Public\Enums\InboxScanStatus::RateLimited->value && $inbox->retryAttempts > 0) {
                        // Mirror InboxScanStateMachine::BACKOFF_SCHEDULE [60,300,900,3600].
                        $schedule = [60, 300, 900, 3600];
                        $idx = max(0, min(count($schedule) - 1, $inbox->retryAttempts - 1));
                        $seconds = $schedule[$idx];
                        if ($seconds < 60) {
                            $retryDetail = Lang::get('email-scan::inboxes.retry_seconds', ['n' => $seconds]);
                        } elseif ($seconds < 3600) {
                            $retryDetail = Lang::get('email-scan::inboxes.retry_minutes', ['n' => intdiv($seconds, 60)]);
                        } else {
                            $retryDetail = Lang::get('email-scan::inboxes.retry_hours', ['n' => intdiv($seconds, 3600)]);
                        }
                    }

                    $errorTooltipId = $inbox->status === \Modules\EmailScan\Public\Enums\InboxScanStatus::Error->value && $inbox->errorMessage !== null
                        ? 'inbox-error-'.$inbox->inboxId
                        : null;
                @endphp
                <li class="flex min-h-16 items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-slate-900 dark:text-slate-100">{{ $inbox->email }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {{ $providerLabel }} · {{ $lastScanText }} · {{ Lang::get('email-scan::inboxes.window_prefix') }} {{ $windowText }}
                            <button
                                type="button"
                                wire:click="editWindow({{ $inbox->inboxId }})"
                                class="ml-1 underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                            >{{ Lang::get('email-scan::inboxes.edit') }}</button>
                        </p>
                        @if ($errorTooltipId !== null)
                            <p
                                id="{{ $errorTooltipId }}"
                                class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400"
                                role="note"
                            >{{ \Illuminate\Support\Str::limit((string) $inbox->errorMessage, 200) }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if ($errorTooltipId !== null)
                            <flux:badge color="{{ $badgeColor }}" aria-describedby="{{ $errorTooltipId }}">{{ $badgeLabel }}</flux:badge>
                        @else
                            <flux:badge color="{{ $badgeColor }}">{{ $badgeLabel }}</flux:badge>
                        @endif

                        @if ($retryDetail !== null)
                            <span class="text-xs text-amber-700 dark:text-amber-300">{{ $retryDetail }}</span>
                        @endif

                        @if ($inbox->status === \Modules\EmailScan\Public\Enums\InboxScanStatus::NeedsReauth->value)
                            <a
                                href="{{ route('oauth.connect', ['provider' => $inbox->provider]) }}?inbox_id={{ $inbox->inboxId }}"
                                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-sm font-medium text-rose-600 hover:bg-rose-100 focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
                            >{{ Lang::get('email-scan::inboxes.reconnect') }}</a>
                        @endif

                        <button
                            type="button"
                            @if ($scanDisabled)
                                disabled
                                aria-disabled="true"
                                title="{{ Lang::get('email-scan::inboxes.scan_in_progress_title') }}"
                            @endif
                            wire:click="scanNow({{ $inbox->inboxId }})"
                            class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2.5 py-1 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900 {{ $scanDisabled ? 'cursor-not-allowed opacity-60' : '' }}"
                        >{{ Lang::get('email-scan::inboxes.scan_now') }}</button>
                        <button
                            type="button"
                            wire:click="disconnect({{ $inbox->inboxId }})"
                            wire:confirm="{{ Lang::get('email-scan::inboxes.disconnect') }}"
                            data-testid="disconnect-inbox-{{ $inbox->inboxId }}"
                            class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2.5 py-1 text-sm font-medium text-slate-600 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-900"
                        >{{ Lang::get('email-scan::inboxes.disconnect') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>

        {{-- "Add another inbox" card pair per UI-SPEC § Add-inbox card pair. --}}
        <section class="mt-12">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::inboxes.add_another') }}</h2>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-lg border border-slate-200 bg-white p-6 space-y-4 dark:bg-slate-950 dark:border-slate-700">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Gmail</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::inboxes.gmail_card_body') }}</p>
                    <button
                        type="button"
                        wire:click="openWizard('{{ \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value }}')"
                        class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                    >{{ Lang::get('email-scan::inboxes.connect_gmail') }}</button>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-6 space-y-4 dark:bg-slate-950 dark:border-slate-700">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Microsoft 365</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::inboxes.microsoft_card_body') }}</p>
                    <button
                        type="button"
                        wire:click="openWizard('{{ \Modules\EmailScan\Public\Enums\MailProvider::Microsoft->value }}')"
                        class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                    >{{ Lang::get('email-scan::inboxes.connect_microsoft') }}</button>
                </div>
            </div>
        </section>
    @endif

    {{-- Discovered senders panel per UI-SPEC § Discovered-senders panel.
         Renders only when there is at least one candidate row above the
         2-occurrences-in-90-days threshold (DiscoveredSenderQuery applies
         the threshold; the Blade simply branches on count). When zero
         candidates exist the panel does NOT render (UI-SPEC § Empty
         state: discovery is a background feature; advertising emptiness
         adds noise). --}}
    @if (count($discoveredCandidates) > 0)
        <section class="mt-12 rounded-lg border border-slate-200 bg-slate-50 p-6 space-y-4 dark:bg-slate-900 dark:border-slate-700">
            <header>
                <h2 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::inboxes.discovered_heading') }}</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {!! Lang::get('email-scan::inboxes.discovered_body') !!}
                </p>
            </header>
            <ul class="space-y-3">
                @foreach ($discoveredCandidates as $cand)
                    @php
                        $fallbackName = strstr($cand->senderEmail, '@', true);
                        $displayName = $cand->senderName ?? ($fallbackName === false ? $cand->senderEmail : $fallbackName);
                        $lastSeenHuman = \Carbon\CarbonImmutable::instance($cand->lastSeenAt)->diffForHumans();
                    @endphp
                    <li
                        wire:key="discovered-{{ $cand->id }}"
                        class="flex items-center justify-between gap-4 rounded-md border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-slate-900 dark:text-slate-100">{{ $cand->senderEmail }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $displayName }} · {{ Lang::get('email-scan::inboxes.last_seen') }} {{ $lastSeenHuman }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                class="text-xs text-slate-500 dark:text-slate-400"
                                style="font-variant-numeric: tabular-nums;"
                            >{{ Lang::get('email-scan::inboxes.seen_times', ['count' => $cand->occurrenceCount]) }}</span>
                            <button
                                type="button"
                                wire:click="promoteSender({{ $cand->id }})"
                                aria-label="{{ Lang::get('email-scan::inboxes.add_aria', ['email' => $cand->senderEmail]) }}"
                                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                            >{{ Lang::get('email-scan::inboxes.add') }}</button>
                            <button
                                type="button"
                                wire:click="dismissSender({{ $cand->id }})"
                                aria-label="{{ Lang::get('email-scan::inboxes.dismiss_aria', ['email' => $cand->senderEmail]) }}"
                                class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500 hover:bg-slate-200 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700"
                            >{{ Lang::get('email-scan::inboxes.dismiss') }}</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Backfill window modal Livewire SFC. Mounted unconditionally so the
         editWindow() action + the post-OAuth-callback mount() hook can
         dispatch the backfill-window:open event to open it scoped to the
         right inbox. --}}
    <livewire:email-scan.backfill-window-modal />

    {{-- Wizard modal (single SFC; branches on the $provider property
         to render the Gmail or Microsoft 365 variant). Mounted
         unconditionally so the openWizard() action can dispatch
         modal-show to open it. --}}
    <livewire:email-scan.oauth-client-wizard-modal />
</div>
