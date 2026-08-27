<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Country;
use Modules\Core\Public\Services\UserCountry;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingConnectException;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Support\ConsentWindow;
use RuntimeException;

final class OpenBankingConnectController
{
    // Enable Banking resolves an ASPSP by name AND country, so a reader who has
    // named no country still needs one sent. The two banks the wizard curates
    // are Dutch, so that is the country to fall back to — never the one to
    // assume over a reader who has said otherwise.
    private const FALLBACK_ASPSP_COUNTRY = 'NL';

    public function __construct(
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly EnableBankingHttpClient $client,
        private readonly OpenBankingStateRepository $oauthState,
        private readonly CurrentUser $currentUser,
        private readonly UserCountry $countries,
        private readonly Clock $clock,
        private readonly Redirector $redirector,
        private readonly LoopbackRedirectUri $loopback,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        try {
            $consentUrl = $this->resolveConsentUrl($request);
        } catch (RuntimeException $e) {
            // Every refusal subclasses RuntimeException and carries a
            // user-facing reason, so one flash handles all of them.
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
            country: $this->aspspCountry(),
            redirectUrl: $redirectUri,
            scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
            validUntil: $this->clock->now()->addDays(ConsentWindow::VALID_FOR_DAYS),
        );

        $consentUrl = $response['url'] ?? null;
        if (! is_string($consentUrl) || $consentUrl === '') {
            throw OpenBankingConnectException::noConsentUrl();
        }

        return $consentUrl;
    }

    private function aspspCountry(): string
    {
        $country = Country::tryFrom($this->countries->current($this->currentUser->id()));

        return $country === null
            ? self::FALLBACK_ASPSP_COUNTRY
            : strtoupper($country->value);
    }

    private function resolveScaHost(string $consentUrl): string
    {
        $scaHost = parse_url($consentUrl, PHP_URL_HOST);
        if (! is_string($scaHost) || $scaHost === '') {
            throw OpenBankingConnectException::unparseableConsentUrl();
        }

        $scaHost = strtolower($scaHost);

        // This host is about to enter the egress allow-list, so a loopback,
        // link-local, private or bare host in the response would widen it to an
        // internal target. Reject before persisting.
        if (! $this->isPublicScaHost($scaHost)) {
            throw OpenBankingConnectException::nonPublicConsentHost();
        }

        return $scaHost;
    }

    private function guardConsentRedirect(string $consentUrl, string $scaHost): void
    {
        // An outward redirect target: https and a host matching the SCA host
        // just allow-listed, or this becomes an open redirect.
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

    // Special-use names that resolve inside the network rather than on the
    // public internet (RFC 6761/8375, plus the cloud metadata suffix).
    private const RESERVED_SUFFIXES = ['.local', '.localhost', '.internal', '.home.arpa', '.invalid'];

    // A strict LDH name of at least two labels whose last label is alphabetic.
    // The alphabetic TLD is what does the work: it is the one rule that
    // rejects every numeric notation at once.
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/';

    // Fails CLOSED, like RelayConfig::isLanHost. Falling through to "contains
    // a dot" answered "public" for every notation FILTER_VALIDATE_IP cannot
    // parse -- 0177.0.0.1, 127.1, 0x7f.0x0.0x0.0x1, [::ffff:127.0.0.1] -- for a
    // value both allow-listed for egress and handed to an outward redirect.
    private function isPublicScaHost(string $host): bool
    {
        $host = strtolower($host);

        // One trailing dot is a legal absolute-name suffix and normalises away;
        // anything else with an empty label is malformed.
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        if (preg_match(self::HOSTNAME_PATTERN, $host) !== 1) {
            return false;
        }

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
