@use('Modules\Core\Public\Support\Lang')
{{-- Consent-expiry banner (Surface B5, Req 7/8). A status notice (standard
     1px rose border), NOT the B2 loud-modal treatment — this fires on an
     already-connected user whose consent lapsed, not a first-time consent
     gate. `role="alert"` — an urgent, unprompted state change the user
     must notice. --}}
@if ($enabled && $consentStatus === 'expired')
    <div
        role="alert"
        class="rounded-xl border border-rose-300 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-950"
        data-testid="open-banking-consent-expired-banner"
    >
        <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">{{ Lang::get('openbanking::messages.consent_banner.heading') }}</p>
        <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">
            {{ Lang::get('openbanking::messages.consent_banner.body', ['when' => $this->lastSuccessfulSyncRelative() ?? Lang::get('openbanking::messages.consent_banner.never')]) }}
        </p>
        <button
            type="button"
            wire:click="reconnect"
            class="mt-3 inline-flex min-h-[44px] items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white
                   hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                   dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
            data-testid="ob-reconnect-button"
        >{{ Lang::get('openbanking::messages.consent_banner.reconnect') }}</button>
    </div>
@endif
