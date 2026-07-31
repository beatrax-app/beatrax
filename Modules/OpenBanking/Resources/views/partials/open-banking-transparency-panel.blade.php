{{-- Transparency panel (Surface B4, Req 6). Rendered whenever OB is
     enabled, in ANY consent state — this is the trust-defining surface,
     never hidden behind a disclosure toggle. "Last successful sync" is
     ALWAYS shown (Req 7's never-stale-as-fresh invariant: it is the ONLY
     freshness indicator anywhere on this page); "Last attempt" renders
     ONLY when the last attempt did not succeed. --}}
@if ($enabled)
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950" data-testid="open-banking-transparency-panel">
        <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-3">
            <dt class="text-xs text-slate-500 dark:text-slate-400">Aggregator</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $aggregator }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">Bank</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $bankDisplayName }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">Consent status</dt>
            <dd class="text-right">
                @if ($consentStatus === 'expired')
                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-950 dark:text-rose-300" data-testid="ob-consent-pill">Expired — reconnect</span>
                @elseif ($consentStatus === 'expiring')
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300" data-testid="ob-consent-pill">Expiring soon</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" data-testid="ob-consent-pill">Connected</span>
                @endif
            </dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">What&rsquo;s fetched</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $whatsFetched }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">Last successful sync</dt>
            <dd class="text-right text-sm tabular-nums text-slate-900 dark:text-slate-100" data-testid="ob-last-successful-sync">
                {{ $this->lastSuccessfulSyncDisplay() ?? 'Never' }}
            </dd>

            @if ($this->lastAttemptFailed())
                <dt class="text-xs text-slate-500 dark:text-slate-400">Last attempt</dt>
                <dd class="text-right text-sm tabular-nums text-rose-600 dark:text-rose-400" data-testid="ob-last-attempt">
                    {{ $this->lastAttemptDisplay() }} — failed ({{ $lastAttemptStatus === 'consent_failed' ? 'consent expired' : 'error' }})
                </dd>
            @endif
        </dl>

        <div class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
            <button
                type="button"
                wire:click="startDisconnect"
                class="inline-flex min-h-[44px] items-center rounded-md border border-rose-300 px-3 py-1.5 text-sm font-medium text-rose-600
                       hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2
                       dark:border-rose-800 dark:text-rose-400 dark:hover:bg-rose-950"
                data-testid="ob-disconnect-button"
            >Disconnect</button>
        </div>
    </div>
@endif
