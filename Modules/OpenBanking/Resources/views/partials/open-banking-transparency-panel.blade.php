@use('Modules\Core\Public\Support\Lang')
{{-- Transparency panel (Surface B4, Req 6). Rendered whenever OB is
     enabled, in ANY consent state — this is the trust-defining surface,
     never hidden behind a disclosure toggle. "Last successful sync" is
     ALWAYS shown (Req 7's never-stale-as-fresh invariant: it is the ONLY
     freshness indicator anywhere on this page); "Last attempt" renders
     ONLY when the last attempt did not succeed. --}}
@if ($enabled)
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950" data-testid="open-banking-transparency-panel">
        <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-3">
            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.aggregator_label') }}</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $aggregator }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.bank_label') }}</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $bankDisplayName }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.consent_status_label') }}</dt>
            <dd class="text-right">
                @if ($consentStatus === 'expired')
                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-950 dark:text-rose-300" data-testid="ob-consent-pill">{{ Lang::get('openbanking::messages.transparency.pill_expired') }}</span>
                @elseif ($consentStatus === 'expiring')
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300" data-testid="ob-consent-pill">{{ Lang::get('openbanking::messages.transparency.pill_expiring') }}</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" data-testid="ob-consent-pill">{{ Lang::get('openbanking::messages.transparency.pill_connected') }}</span>
                @endif
            </dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.whats_fetched_label') }}</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $whatsFetched }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.last_successful_sync_label') }}</dt>
            <dd class="text-right text-sm tabular-nums text-slate-900 dark:text-slate-100" data-testid="ob-last-successful-sync">
                {{ $this->lastSuccessfulSyncDisplay() ?? Lang::get('openbanking::messages.transparency.never') }}
            </dd>

            @if ($this->lastAttemptFailed())
                <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.last_attempt_label') }}</dt>
                <dd class="text-right text-sm tabular-nums text-rose-600 dark:text-rose-400" data-testid="ob-last-attempt">
                    {{ Lang::get('openbanking::messages.transparency.last_attempt_failed', ['when' => $this->lastAttemptDisplay(), 'reason' => $lastAttemptStatus === 'consent_failed' ? Lang::get('openbanking::messages.transparency.reason_consent_expired') : Lang::get('openbanking::messages.transparency.reason_error')]) }}
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
            >{{ Lang::get('openbanking::messages.transparency.disconnect_button') }}</button>
        </div>
    </div>
@endif
