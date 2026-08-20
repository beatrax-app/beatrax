<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Exceptions\OpenBankingConnectException;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use RuntimeException;

final class OpenBankingConnectController
{
    // Enable Banking discovers ASPSPs by country; ASN/SNS (this project's
    // proven targets) are both Dutch banks.
    private const COUNTRY = 'NL';

    // Kept in sync with the identically-named constant on
    // OpenBankingCallbackController.
    private const CONSENT_VALID_FOR_DAYS = 180;

    public function __construct(
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly EnableBankingHttpClient $client,
        private readonly OpenBankingStateRepository $oauthState,
        private readonly CurrentUser $currentUser,
        private readonly Clock $clock,
        private readonly Redirector $redirector,
        private readonly LoopbackRedirectUri $loopback,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        try {
            $consentUrl = $this->resolveConsentUrl($request);
        } catch (RuntimeException $e) {
            // OpenBankingConnectException, SecretsWriteFailed and the Enable
            // Banking client's own failures all subclass RuntimeException and
            // carry a user-facing reason — one flash handles every refusal.
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', $e->getMessage());
        }

        return $this->redirector->away($consentUrl);
    }

    private function resolveConsentUrl(Request $request): string
    {
        if (! $this->secrets->hasApplication()) {
            throw OpenBankingConnectException::wizardIncomplete();
        }

        $institutionIdRaw = $request->query('institution_id');
        $institutionId = is_string($institutionIdRaw) ? trim($institutionIdRaw) : '';
        if ($institutionId === '') {
            throw OpenBankingConnectException::noBankChosen();
        }

        $consentUrl = $this->initiateConsent($institutionId);
        $scaHost = $this->resolveScaHost($consentUrl);

        $this->persistResolvedScaHost($scaHost, $institutionId);
        $this->guardConsentRedirect($consentUrl, $scaHost);

        return $consentUrl;
    }

    private function initiateConsent(string $institutionId): string
    {
        $state = $this->oauthState->issueState($this->currentUser->id());
        $redirectUri = $this->loopback->forProvider('open-banking', scheme: 'https')
            .'?state='.rawurlencode($state);

        $response = $this->client->initiateAuth(
            institutionId: $institutionId,
            country: self::COUNTRY,
            redirectUrl: $redirectUri,
            scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
            validUntil: $this->clock->now()->addDays(self::CONSENT_VALID_FOR_DAYS),
        );

        $consentUrl = $response['url'] ?? null;
        if (! is_string($consentUrl) || $consentUrl === '') {
            throw OpenBankingConnectException::noConsentUrl();
        }

        return $consentUrl;
    }

    private function resolveScaHost(string $consentUrl): string
    {
        $scaHost = parse_url($consentUrl, PHP_URL_HOST);
        if (! is_string($scaHost) || $scaHost === '') {
            throw OpenBankingConnectException::unparseableConsentUrl();
        }

        $scaHost = strtolower($scaHost);

        // The SCA host is about to be persisted into the egress allow-list.
        // An aggregator response (or TLS-defeating MITM) supplying a
        // loopback/link-local/private/bare host must never widen that
        // allow-list to an internal target — reject it before persisting.
        if (! $this->isPublicScaHost($scaHost)) {
            throw OpenBankingConnectException::nonPublicConsentHost();
        }

        return $scaHost;
    }

    private function guardConsentRedirect(string $consentUrl, string $scaHost): void
    {
        // The consent URL is an outward redirect target — require https and
        // that its host matches the SCA host just resolved and allow-listed,
        // otherwise fail the flow rather than emit an open redirect.
        $consentScheme = parse_url($consentUrl, PHP_URL_SCHEME);
        $consentHost = parse_url($consentUrl, PHP_URL_HOST);
        if (! is_string($consentScheme) || strtolower($consentScheme) !== 'https'
            || ! is_string($consentHost) || strtolower($consentHost) !== $scaHost) {
            throw OpenBankingConnectException::unsafeConsentUrl();
        }
    }

    private function persistResolvedScaHost(string $scaHost, string $institutionId): void
    {
        $existing = $this->secrets->load();
        if ($existing === null) {
            return;
        }

        $this->secrets->save(new OpenBankingCredentials(
            applicationId: $existing->applicationId,
            privateKeyPem: $existing->privateKeyPem,
            sessionId: $existing->sessionId,
            consentExpiresAt: $existing->consentExpiresAt,
            bankScaHost: $scaHost,
            institutionId: $institutionId,
        ));
    }

    private function isPublicScaHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        // IP literal (v4 or v6): accept only public, non-reserved addresses.
        // Otherwise require a dotted FQDN, rejecting bare single-label hosts.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return str_contains($host, '.');
    }
}
