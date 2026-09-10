<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Actions;

use Closure;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Country;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\PublicHost;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingConnectException;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Support\ConsentWindow;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md#consent--oauth-dance
 */
final readonly class StartBankConsent
{
    // Enable Banking resolves an ASPSP by name AND country, so a reader who has
    // named no country still needs one sent. The two banks the wizard curates
    // are Dutch, so that is the country to fall back to — never the one to
    // assume over a reader who has said otherwise.
    private const string FALLBACK_ASPSP_COUNTRY = 'NL';

    public function __construct(
        private OpenBankingSecretsRepository $secrets,
        private EnableBankingHttpClient $client,
        private UserCountry $countries,
        private CurrentUser $currentUser,
        private Clock $clock,
    ) {}

    // The URL this returns has already been certified safe to redirect to:
    // resolving the SCA host, allow-listing it for egress and checking the
    // redirect target against it are one decision, and splitting the check
    // off from the resolution would leave two files agreeing by hand.
    /**
     * @param  Closure(): string  $callbackUri  called once both refusals are past
     */
    public function __invoke(string $institutionId, Closure $callbackUri): string
    {
        $userId = $this->currentUser->id();
        $application = $this->secrets->load($userId);
        if ($application === null || $application->applicationId === '') {
            throw OpenBankingConnectException::wizardIncomplete();
        }

        if ($institutionId === '') {
            throw OpenBankingConnectException::noBankChosen();
        }

        $consentUrl = $this->initiateConsent($application, $institutionId, $callbackUri());
        $scaHost = $this->resolveScaHost($consentUrl);

        // Merged into this institution's own record, so a consent begun at a
        // second bank leaves the first one's live session where it was.
        $this->secrets->rememberScaHost($userId, $institutionId, $scaHost);
        $this->guardConsentRedirect($consentUrl, $scaHost);

        return $consentUrl;
    }

    private function initiateConsent(OpenBankingCredentials $application, string $institutionId, string $callbackUri): string
    {
        $response = $this->client->initiateAuth(
            credentials: $application,
            institutionId: $institutionId,
            country: $this->aspspCountry(),
            redirectUrl: $callbackUri,
            scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
            validUntil: ConsentWindow::expiresAfter($this->clock->now()),
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

    // Fails CLOSED, like RelayConfig::isLanHost, for a value both allow-listed
    // for egress and handed to an outward redirect. PublicHost holds the rule:
    // it lived here and in ExternalUrl at once, and only this copy was ever
    // corrected.
    private function isPublicScaHost(string $host): bool
    {
        return PublicHost::names($host);
    }
}
