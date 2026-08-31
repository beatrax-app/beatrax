@use('Modules\Core\Public\Support\Lang')
@use('Modules\OpenBanking\Internal\Enums\ConsentStatus')
{{-- A status notice rather than the loud-modal treatment the first-time
     third-party warning gets: this fires on an already-connected reader whose
     consent lapsed, not on somebody about to share their data for the first
     time. It stays role="alert" because nothing prompted it. --}}
@if ($enabled && ConsentStatus::from($consentStatus)->needsReconnect())
    @php($revoked = $consentStatus === ConsentStatus::Revoked->value)
    <x-core::alert
        tone="danger"
        role="alert"
        data-testid="open-banking-consent-expired-banner"
    >
        <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">{{ Lang::get($revoked ? 'openbanking::messages.consent_banner.heading_revoked' : 'openbanking::messages.consent_banner.heading') }}</p>
        <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">
            {{ Lang::get($revoked ? 'openbanking::messages.consent_banner.body_revoked' : 'openbanking::messages.consent_banner.body', ['when' => $this->lastSuccessfulSyncRelative() ?? Lang::get('openbanking::messages.consent_banner.never')]) }}
        </p>
        <x-core::neutral-button
            class="mt-3 min-h-[44px]"
            wire:click="reconnect"
            data-testid="ob-reconnect-button"
        >{{ Lang::get('openbanking::messages.consent_banner.reconnect') }}</x-core::neutral-button>
    </x-core::alert>
@endif
