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
        {{-- Connected-inboxes table (Plan 03: minimal — status badge stub; full row actions arrive in Plan 07). --}}
        <ul class="space-y-4">
            @foreach ($inboxes as $inbox)
                <li class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 gap-4 min-h-16">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-slate-900 truncate">{{ $inbox->email }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            @php
                                $providerLabel = $inbox->provider === 'gmail' ? 'Gmail' : 'Microsoft 365';
                                $windowText = $inbox->backfillWindowMonths === 1
                                    ? '1 month'
                                    : ($inbox->backfillWindowMonths . ' months');
                                $lastScanText = $inbox->lastScanAt === null
                                    ? 'not scanned yet'
                                    : 'last scanned ' . \Carbon\CarbonImmutable::instance($inbox->lastScanAt)->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, short: true);
                            @endphp
                            {{ $providerLabel }} · {{ $lastScanText }} · Window: {{ $windowText }}
                            <button
                                type="button"
                                wire:click="$dispatch('toast', { message: 'Backfill window editing arrives in the next plan' })"
                                class="ml-1 underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-slate-900"
                            >Edit</button>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center">
                        <flux:badge color="slate">Idle</flux:badge>
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

    {{-- Placeholder for backfill modal — arrives in a later plan. The OAuth callback
         sets the openBackfillForInboxId flash; the modal SFC will read it. --}}
    @if ($openBackfillForInboxId !== null)
        {{-- Placeholder until the backfill-window-modal Livewire SFC lands. --}}
    @endif

    {{-- Wizard modal is mounted by the OAuthClientWizardModal SFC; it
         opens via the `modal-show` event dispatched from openWizard(). --}}
</div>
