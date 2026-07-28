<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use League\OAuth2\Client\Provider\Google;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Builds the League provider objects that GoogleOAuthProvider and
 * MicrosoftOAuthProvider talk through.
 *
 * They used to construct these inline, which put the only seam for the token
 * exchange and refresh calls inside a private method. Everything past
 * "build the authorization URL" was therefore unreachable from a test without
 * real network, and it stayed at ~2% covered — the token refresh, the
 * invalid_grant handling that decides whether a user is asked to reconnect,
 * and the userinfo read.
 *
 * $httpClient is the reason this class exists. League treats the HTTP client
 * as an injectable collaborator; left null the provider builds its own Guzzle
 * instance, which is what production wants. A test supplies one backed by a
 * mock handler and drives the whole exchange without a socket.
 */
final class OAuthProviderFactory
{
    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    /**
     * @throws RuntimeException when the OAuth client has not been configured
     */
    public function google(string $redirectUri): Google
    {
        $client = $this->clientOrFail('gmail', 'Google');

        return new Google([
            'clientId' => $client['client_id'],
            'clientSecret' => $client['client_secret'],
            'redirectUri' => $redirectUri,
            'accessType' => 'offline',
            'prompt' => 'consent',
        ], $this->collaborators());
    }

    /**
     * @throws RuntimeException when the OAuth client has not been configured
     */
    public function microsoft(string $redirectUri): Azure
    {
        $client = $this->clientOrFail('microsoft', 'Microsoft');

        return new Azure([
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
     * @throws RuntimeException
     */
    private function clientOrFail(string $provider, string $label): array
    {
        $client = $this->secrets->loadProviderClient($provider);
        if ($client === null) {
            throw new RuntimeException(
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
