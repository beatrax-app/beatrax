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
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Public\Services\SecretsWriteFailed;
use RuntimeException;

/**
 * Invokable controller routed at GET /oauth/connect/open-banking —
 * kicks off the Enable Banking consent/SCA dance (D-08), mirroring
 * `Modules\EmailScan\Internal\Http\Controllers\OAuthConnectController`
 * but adapted for Enable Banking's `POST /auth` step (there is exactly
 * one provider, so this controller has no `{provider}` route segment or
 * `match()` dispatch).
 *
 * Gate 0/A2 resolution: Enable Banking's Control Panel requires an
 * HTTPS-only registered redirect URI — unlike EmailScan's gmail/
 * microsoft loopback, which is plain HTTP, this controller requests the
 * `https` variant from the promoted `LoopbackRedirectUri`. Terminating
 * TLS on that loopback listener (a self-signed local certificate) is
 * tracked as deferred follow-up infrastructure work (19-05-SUMMARY.md).
 *
 * The institution id is supplied by the caller (the onboarding wizard's
 * bank-pick step, 19-06) via `?institution_id=...` — never hardcoded
 * (Req 12). The per-flow CSRF `state` is embedded in the redirect_url's
 * own query string (Enable Banking's `/auth` request body has no
 * dedicated `state` field, unlike a standard OAuth2 authorize request —
 * RESEARCH.md Architecture Patterns §3) so it round-trips back to the
 * callback alongside the bank's own `?code=...` append.
 *
 * After `/auth` returns the consent URL, this controller resolves the
 * bank's SCA host from that URL and persists it into the credentials
 * file (D-11/T-19-05-05) so the dynamic SSRF allow-list can validate any
 * later egress to that host.
 */
final class OpenBankingConnectController
{
    /**
     * Enable Banking discovers ASPSPs by country; ASN/SNS (this
     * project's proven targets) are both Dutch banks.
     */
    private const COUNTRY = 'NL';

    /**
     * Requested consent validity window (SPEC: PSD2 SCA re-auth is
     * typically required every 90-180 days); the bank/aggregator caps
     * this to its own maximum_consent_validity regardless. Kept in sync
     * with the identically-named constant on
     * `OpenBankingCallbackController` — centralize via config/open-
     * banking.php if a third caller ever needs this value.
     */
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
        if (! $this->secrets->hasApplication()) {
            return $this->redirector
                ->route('settings')
                ->with('open_banking_failed', 'Finish the Open Banking setup wizard first.');
        }

        $institutionIdRaw = $request->query('institution_id');
        $institutionId = is_string($institutionIdRaw) ? trim($institutionIdRaw) : '';
        if ($institutionId === '') {
            return $this->redirector
                ->route('settings')
                ->with('open_banking_failed', 'Choose a bank before connecting.');
        }

        $userId = $this->currentUser->id();
        $state = $this->oauthState->issueState($userId);

        $redirectUri = $this->loopback->forProvider('open-banking', scheme: 'https')
            .'?state='.rawurlencode($state);

        try {
            $response = $this->client->initiateAuth(
                institutionId: $institutionId,
                country: self::COUNTRY,
                redirectUrl: $redirectUri,
                scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
                validUntil: $this->clock->now()->addDays(self::CONSENT_VALID_FOR_DAYS),
            );
        } catch (RuntimeException $e) {
            return $this->redirector
                ->route('settings')
                ->with('open_banking_failed', $e->getMessage());
        }

        $consentUrl = $response['url'] ?? null;
        if (! is_string($consentUrl) || $consentUrl === '') {
            return $this->redirector
                ->route('settings')
                ->with('open_banking_failed', 'Enable Banking did not return a consent URL.');
        }

        $scaHost = parse_url($consentUrl, PHP_URL_HOST);
        if (! is_string($scaHost) || $scaHost === '') {
            return $this->redirector
                ->route('settings')
                ->with('open_banking_failed', 'Enable Banking returned an unparseable consent URL.');
        }

        try {
            $this->persistResolvedScaHost(strtolower($scaHost), $institutionId);
        } catch (SecretsWriteFailed $e) {
            return $this->redirector
                ->route('settings')
                ->with('open_banking_failed', $e->getMessage());
        }

        return $this->redirector->away($consentUrl);
    }

    /**
     * Merge the resolved bank SCA host + chosen institution id into the
     * existing application credentials (application_id + private key
     * PEM are untouched). Guarded by `hasApplication()` above, so
     * `load()` is expected to return non-null here.
     */
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
}
