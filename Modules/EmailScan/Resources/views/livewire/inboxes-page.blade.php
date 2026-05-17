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
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Inboxes</h1>
        <p class="mt-2 text-sm text-slate-500">
            Connect Gmail and Microsoft 365 inboxes so diederik can scan them for receipts.
        </p>
    </header>

    @if (session()->has('oauth_canceled'))
        <aside role="status" class="mb-6 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-600">
            Connection canceled. {{ session('oauth_canceled') }}
        </aside>
    @endif

    @if (session()->has('oauth_failed'))
        <aside role="status" class="mb-6 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-600">
            Couldn't complete the connection. {{ session('oauth_failed') }}
        </aside>
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
            class="rounded-md border border-slate-200 bg-slate-50 p-4 space-y-2 mb-6"
            aria-live="polite"
        >
            @foreach ($activeBackfills as $inbox)
                <div class="flex items-center justify-between text-xs text-slate-700">
                    <span>
                        Backfilling {{ $inbox->provider === 'gmail' ? 'Gmail' : 'Microsoft 365' }} ({{ $inbox->email }}):
                        <span style="font-variant-numeric: tabular-nums;">{{ number_format($inbox->backfillFetchedCount) }} / ~{{ number_format($inbox->backfillTotalEstimated ?? 0) }}</span>
                        messages
                    </span>
                </div>
            @endforeach
        </section>
    @endif

    @if (count($inboxes) === 0)
        {{-- Empty-state hero per UI-SPEC § Empty state hero (zero inboxes connected). --}}
        <section class="mx-auto max-w-md text-center mt-12">
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Connect your email</h2>
            <p class="mt-2 text-sm text-slate-500">
                Import receipts from PayPal, ICS Cards, Google Play, and other merchants by giving diederik
                read-only access to one or more of your inboxes.
            </p>
            <div class="mt-8 flex items-center justify-center gap-4">
                <button
                    type="button"
                    wire:click="openWizard('gmail')"
                    class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                >Connect Gmail</button>
                <button
                    type="button"
                    wire:click="openWizard('microsoft')"
                    class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                >Connect Microsoft 365</button>
            </div>
            <p class="mt-4 text-xs text-slate-500">
                diederik only reads messages. It never sends, labels, moves, or deletes anything in your inbox.
            </p>
        </section>
    @else
        {{-- Connected-inboxes table — full row chrome with status badge
             matrix + Scan-Now / Reconnect / Window-edit actions
             (UI-SPEC § Connected Inboxes Table + § Status Badge Matrix). --}}
        <ul class="space-y-4">
            @foreach ($inboxes as $inbox)
                @php
                    $providerLabel = $inbox->provider === 'gmail' ? 'Gmail' : 'Microsoft 365';
                    $windowText = $inbox->backfillWindowMonths === 1
                        ? '1 month'
                        : ($inbox->backfillWindowMonths.' months');
                    $lastScanText = $inbox->lastScanAt === null
                        ? 'not scanned yet'
                        : 'last scanned '.\Carbon\CarbonImmutable::instance($inbox->lastScanAt)->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, short: true);

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
                        'idle' => 'Idle',
                        'backfilling' => 'Backfilling',
                        'scanning' => 'Scanning',
                        'rate_limited' => 'Rate limited',
                        'needs_reauth' => 'Needs reauth',
                        'error' => 'Error',
                    ][$inbox->status] ?? 'Idle';

                    $scanDisabled = in_array($inbox->status, ['backfilling', 'scanning'], strict: true);

                    // Inline retry-after detail for rate_limited rows.
                    $retryDetail = null;
                    if ($inbox->status === 'rate_limited' && $inbox->retryAttempts > 0) {
                        // Mirror InboxScanStateMachine::BACKOFF_SCHEDULE [60,300,900,3600].
                        $schedule = [60, 300, 900, 3600];
                        $idx = max(0, min(count($schedule) - 1, $inbox->retryAttempts - 1));
                        $seconds = $schedule[$idx];
                        if ($seconds < 60) {
                            $retryDetail = "retrying in {$seconds}s";
                        } elseif ($seconds < 3600) {
                            $retryDetail = 'retrying in '.intdiv($seconds, 60).'m';
                        } else {
                            $retryDetail = 'retrying in '.intdiv($seconds, 3600).'h';
                        }
                    }

                    $errorTooltipId = $inbox->status === 'error' && $inbox->errorMessage !== null
                        ? 'inbox-error-'.$inbox->inboxId
                        : null;
                @endphp
                <li class="flex min-h-16 items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-4">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-slate-900">{{ $inbox->email }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $providerLabel }} · {{ $lastScanText }} · Window: {{ $windowText }}
                            <button
                                type="button"
                                wire:click="editWindow({{ $inbox->inboxId }})"
                                class="ml-1 underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-slate-900"
                            >Edit</button>
                        </p>
                        @if ($errorTooltipId !== null)
                            <p
                                id="{{ $errorTooltipId }}"
                                class="mt-1 truncate text-xs text-slate-500"
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
                            <span class="text-xs text-amber-700">{{ $retryDetail }}</span>
                        @endif

                        @if ($inbox->status === 'needs_reauth')
                            <a
                                href="{{ route('oauth.connect', ['provider' => $inbox->provider]) }}?inbox_id={{ $inbox->inboxId }}"
                                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-sm font-medium text-rose-600 hover:bg-rose-100 focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                            >Reconnect</a>
                        @endif

                        <button
                            type="button"
                            @if ($scanDisabled)
                                disabled
                                aria-disabled="true"
                                title="Scan already in progress"
                            @endif
                            wire:click="scanNow({{ $inbox->inboxId }})"
                            class="inline-flex items-center gap-1 rounded-md border border-slate-200 px-2.5 py-1 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 {{ $scanDisabled ? 'cursor-not-allowed opacity-60' : '' }}"
                        >Scan now</button>
                    </div>
                </li>
            @endforeach
        </ul>

        {{-- "Add another inbox" card pair per UI-SPEC § Add-inbox card pair. --}}
        <section class="mt-12">
            <h2 class="text-base font-semibold text-slate-900">Add another inbox</h2>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                    <p class="text-sm font-semibold text-slate-900">Gmail</p>
                    <p class="text-xs text-slate-500">Connect a Gmail account so diederik can scan it for receipts.</p>
                    <button
                        type="button"
                        wire:click="openWizard('gmail')"
                        class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                    >Connect Gmail</button>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                    <p class="text-sm font-semibold text-slate-900">Microsoft 365</p>
                    <p class="text-xs text-slate-500">Connect a Microsoft 365 or Outlook.com account so diederik can scan it for receipts.</p>
                    <button
                        type="button"
                        wire:click="openWizard('microsoft')"
                        class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                    >Connect Microsoft 365</button>
                </div>
            </div>
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
