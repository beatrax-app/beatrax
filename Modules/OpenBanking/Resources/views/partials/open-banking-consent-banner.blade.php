@use('Modules\Core\Public\Support\Lang')
{{-- Consent-expiry banner (Surface B5). A status notice (standard
     1px rose border), NOT the B2 loud-modal treatment — this fires on an
     already-connected user whose consent lapsed, not a first-time consent
     gate. `role="alert"` — an urgent, unprompted state change the user
     must notice. --}}
@if ($enabled && $consentStatus === 'expired')
    <x-core::alert
        tone="danger"
        role="alert"
        data-testid="open-banking-consent-expired-banner"
    >
        <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">{{ Lang::get('openbanking::messages.consent_banner.heading') }}</p>
        <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">
            {{ Lang::get('openbanking::messages.consent_banner.body', ['when' => $this->lastSuccessfulSyncRelative() ?? Lang::get('openbanking::messages.consent_banner.never')]) }}
        </p>
        <x-core::neutral-button
            class="mt-3 min-h-[44px]"
            wire:click="reconnect"
            data-testid="ob-reconnect-button"
        >{{ Lang::get('openbanking::messages.consent_banner.reconnect') }}</x-core::neutral-button>
    </x-core::alert>
@endif
