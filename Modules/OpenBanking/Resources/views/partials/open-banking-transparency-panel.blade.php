@use('Modules\Core\Public\Support\Lang')
@use('Modules\OpenBanking\Internal\Enums\ConsentStatus')
@use('Modules\OpenBanking\Internal\Enums\SyncAttemptStatus')
{{-- Rendered in ANY consent state and never behind a disclosure toggle: this
     is the surface the reader's trust rests on. Last-successful-sync is the
     only freshness indicator on the page, so it is always shown, while a last
     attempt is worth a row only when it did not succeed. --}}
@if ($enabled)
    <x-core::card data-testid="open-banking-transparency-panel">
        <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-3">
            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.aggregator_label') }}</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $aggregator }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.bank_label') }}</dt>
            <dd class="text-right text-sm text-slate-900 dark:text-slate-100">{{ $bankDisplayName }}</dd>

            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.transparency.consent_status_label') }}</dt>
            <dd class="text-right">
                @if ($consentStatus === ConsentStatus::Revoked->value)
                    <x-core::status-pill tone="danger" data-testid="ob-consent-pill">{{ Lang::get('openbanking::messages.transparency.pill_revoked') }}</x-core::status-pill>
                @elseif ($consentStatus === ConsentStatus::Expired->value)
                    <x-core::status-pill tone="danger" data-testid="ob-consent-pill">{{ Lang::get('openbanking::messages.transparency.pill_expired') }}</x-core::status-pill>
                @elseif ($consentStatus === ConsentStatus::Expiring->value)
                    <x-core::status-pill tone="warning" data-testid="ob-consent-pill">{{ Lang::get('openbanking::messages.transparency.pill_expiring') }}</x-core::status-pill>
                @else
                    <x-core::status-pill tone="positive" data-testid="ob-consent-pill">{{ Lang::get('openbanking::messages.transparency.pill_connected') }}</x-core::status-pill>
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
                    @php
                        // Named from the status the attempt actually recorded: a
                        // walk that stopped early is not the same event as a
                        // refused session, and reading "error" for it sends the
                        // reader looking for a fault there is not.
                        $attemptReason = match (true) {
                            $consentStatus === ConsentStatus::Revoked->value => 'openbanking::messages.transparency.reason_consent_revoked',
                            $lastAttemptStatus === SyncAttemptStatus::ConsentFailed->value => 'openbanking::messages.transparency.reason_consent_expired',
                            $lastAttemptStatus === SyncAttemptStatus::Truncated->value => 'openbanking::messages.transparency.reason_truncated',
                            $lastAttemptStatus === SyncAttemptStatus::NothingImported->value => 'openbanking::messages.transparency.reason_nothing_imported',
                            default => 'openbanking::messages.transparency.reason_error',
                        };
                    @endphp
                    {{ Lang::get('openbanking::messages.transparency.last_attempt_failed', ['when' => $this->lastAttemptDisplay(), 'reason' => Lang::get($attemptReason)]) }}
                </dd>
            @endif
        </dl>
    </x-core::card>
@endif
