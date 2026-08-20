<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use League\OAuth2\Client\Provider\Google;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Http\Client\ClientInterface;
use TheNetworg\OAuth2\Client\Provider\Azure;

// $httpClient is why this class exists: it is the seam a test uses to drive
// the whole token exchange through a mock handler without a socket.
final class OAuthProviderFactory
{
    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    /**
     * @throws OAuthClientNotConfigured when the OAuth client has not been configured
     */
    public function google(string $redirectUri): Google
    {
        $client = $this->clientOrFail(MailProvider::Gmail->value, 'Google');

        return new PkceGoogleProvider([
            'clientId' => $client['client_id'],
            'clientSecret' => $client['client_secret'],
            'redirectUri' => $redirectUri,
            'accessType' => 'offline',
            'prompt' => 'consent',
        ], $this->collaborators());
    }

    /**
     * @throws OAuthClientNotConfigured when the OAuth client has not been configured
     */
    public function microsoft(string $redirectUri): Azure
    {
        $client = $this->clientOrFail(MailProvider::Microsoft->value, 'Microsoft');

        return new PkceAzureProvider([
            'clientId' => $client['client_id'],
            'clientSecret' => $client['client_secret'],
            'redirectUri' => $redirectUri,
            'tenant' => 'common',
            'defaultEndPointVersion' => '2.0',
        ], $this->collaborators());
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}
     *
     * @throws OAuthClientNotConfigured
     */
    private function clientOrFail(string $provider, string $label): array
    {
        $client = $this->secrets->loadProviderClient($provider);
        if ($client === null) {
            throw new OAuthClientNotConfigured(
                $label.' OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }

        return $client;
    }

    /**
     * @return array<string, ClientInterface>
     */
    private function collaborators(): array
    {
        // An empty array leaves League to build its own Guzzle client, which
        // is the production path; passing null explicitly would not.
        return $this->httpClient instanceof ClientInterface
            ? ['httpClient' => $this->httpClient]
            : [];
    }
}
