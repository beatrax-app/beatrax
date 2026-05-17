<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use RuntimeException;
use TheNetworg\OAuth2\Client\Provider\Azure;
use TheNetworg\OAuth2\Client\Token\AccessToken as AzureAccessToken;
use Throwable;

/**
 * Thin wrapper over TheNetworg\OAuth2\Client\Provider\Azure.
 *
 * Owns three concerns the rest of the module relies on:
 *
 *  1. Reading the per-install Azure client id + secret out of the
 *     chmod-600 JSON repository on every call so the controller
 *     never holds credentials in memory across requests.
 *  2. Mapping the league library's IdentityProviderException to the
 *     module's two typed sentinels (InvalidGrantException for the
 *     needs_reauth transition; OAuthExchangeFailed for everything
 *     else) without ever including the raw token or request body in
 *     the message.
 *  3. Always requesting the Mail.Read + offline_access + User.Read
 *     scopes against tenant=common with defaultEndPointVersion=2.0
 *     and prompt=consent so Azure issues a refresh token on every
 *     consent — required for the always-on background scanner.
 *
 * Microsoft Graph refresh tokens are single-use: every refresh-token
 * exchange rotates the refresh_token. The caller persists the new
 * refresh token via OAuthSecretsRepository::rotateRefreshToken.
 *
 * Per the project's DI invariant, the underlying Azure provider is
 * instantiated per call rather than cached as a constructor property
 * because the redirect URI is computed by the caller and may differ
 * across reconnect flows. The chmod-600 read is cheap enough (single
 * file, ~1KB) that the per-call cost is not worth memoising.
 *
 * The class is non-final so the feature tests can substitute a stub
 * subclass via $this->app->instance(...). The contract is enforced
 * by the singleton binding + the constructor signature, not by the
 * final modifier — same pattern OAuthSecretsRepository uses for its
 * performRename() failure-injection hook.
 */
class MicrosoftOAuthProvider
{
    private const MAIL_READ_SCOPE = 'Mail.Read';

    private const OFFLINE_ACCESS_SCOPE = 'offline_access';

    private const USER_READ_SCOPE = 'User.Read';

    private const SCOPE_STRING = self::MAIL_READ_SCOPE.' '.self::OFFLINE_ACCESS_SCOPE.' '.self::USER_READ_SCOPE;

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
    ) {}

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $provider = $this->makeProvider($redirectUri);

        return $provider->getAuthorizationUrl([
            'scope' => [
                self::MAIL_READ_SCOPE,
                self::OFFLINE_ACCESS_SCOPE,
                self::USER_READ_SCOPE,
            ],
            'state' => $state,
            'prompt' => 'consent',
        ]);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): AccessTokenWithEmail
    {
        $provider = $this->makeProvider($redirectUri);

        try {
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $code,
                'scope' => self::SCOPE_STRING,
            ]);
        } catch (IdentityProviderException $e) {
            throw $this->mapIdentityProviderException($e);
        } catch (Throwable $e) {
            throw new OAuthExchangeFailed(
                'Microsoft OAuth token exchange failed: '.$this->safeMessage($e),
            );
        }

        $accessTokenString = $token->getToken();
        $email = $this->readEmailFromToken($provider, $token);

        $expiresAt = null;
        $expires = $token->getExpires();
        if (is_int($expires) && $expires > 0) {
            $expiresAt = (new DateTimeImmutable)->setTimestamp($expires);
        }

        $refreshToken = $token->getRefreshToken();

        return new AccessTokenWithEmail(
            accessToken: $accessTokenString,
            refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            expiresAt: $expiresAt,
            scope: self::SCOPE_STRING,
            email: $email,
        );
    }

    public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
    {
        $client = $this->secrets->loadProviderClient('microsoft');
        if ($client === null) {
            throw new RuntimeException(
                'Microsoft OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }

        $provider = $this->makeProvider($client['redirect_uri']);

        try {
            $token = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
                'scope' => self::SCOPE_STRING,
            ]);
        } catch (IdentityProviderException $e) {
            throw $this->mapIdentityProviderException($e);
        } catch (Throwable $e) {
            throw new OAuthExchangeFailed(
                'Microsoft OAuth refresh failed: '.$this->safeMessage($e),
            );
        }

        $expiresAt = null;
        $expires = $token->getExpires();
        if (is_int($expires) && $expires > 0) {
            $expiresAt = (new DateTimeImmutable)->setTimestamp($expires);
        }

        $newRefresh = $token->getRefreshToken();

        return new AccessTokenWithEmail(
            accessToken: $token->getToken(),
            refreshToken: is_string($newRefresh) && $newRefresh !== '' ? $newRefresh : null,
            expiresAt: $expiresAt,
            scope: self::SCOPE_STRING,
            email: '',
        );
    }

    public function readEmail(string $accessToken): string
    {
        $client = $this->secrets->loadProviderClient('microsoft');
        if ($client === null) {
            throw new RuntimeException(
                'Microsoft OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }
        $provider = $this->makeProvider($client['redirect_uri']);

        $tokenObj = new AzureAccessToken([
            'access_token' => $accessToken,
        ], $provider);

        return $this->readEmailFromToken($provider, $tokenObj);
    }

    private function makeProvider(string $redirectUri): Azure
    {
        $client = $this->secrets->loadProviderClient('microsoft');
        if ($client === null) {
            throw new RuntimeException(
                'Microsoft OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }

        return new Azure([
            'clientId' => $client['client_id'],
            'clientSecret' => $client['client_secret'],
            'redirectUri' => $redirectUri,
            'tenant' => 'common',
            'defaultEndPointVersion' => '2.0',
        ]);
    }

    private function readEmailFromToken(Azure $provider, AccessTokenInterface $token): string
    {
        try {
            // The thenetworg Azure provider's get() takes the token by
            // reference because it auto-rotates an expired token mid
            // call. Reassigning to a local variable keeps the reference
            // semantics off the caller-supplied AccessTokenWithEmail.
            $tokenRef = $token;
            $response = $provider->get('https://graph.microsoft.com/v1.0/me', $tokenRef);

            if (! is_array($response)) {
                throw new OAuthExchangeFailed(
                    'Microsoft Graph /me response was not an associative array.',
                );
            }

            // Microsoft Graph returns both `mail` and `userPrincipalName`.
            // For consumer Outlook.com accounts `mail` is often null and
            // `userPrincipalName` holds the routable address; for work /
            // school accounts both fields typically match.
            $mail = $response['mail'] ?? null;
            if (is_string($mail) && $mail !== '') {
                return $mail;
            }

            $upn = $response['userPrincipalName'] ?? null;
            if (is_string($upn) && $upn !== '') {
                return $upn;
            }

            return '';
        } catch (OAuthExchangeFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OAuthExchangeFailed(
                'Microsoft Graph /me read failed: '.$this->safeMessage($e),
            );
        }
    }

    private function mapIdentityProviderException(IdentityProviderException $e): RuntimeException
    {
        $message = $this->safeMessage($e);
        $body = $e->getResponseBody();
        $bodyError = '';
        if (is_array($body)) {
            $bodyError = isset($body['error']) && is_string($body['error']) ? $body['error'] : '';
        }

        if (str_contains($message, 'invalid_grant') || $bodyError === 'invalid_grant') {
            return new InvalidGrantException(
                'Microsoft OAuth refresh rejected with invalid_grant — reconnect required.',
            );
        }

        return new OAuthExchangeFailed('Microsoft OAuth exchange failed: '.$message);
    }

    private function safeMessage(Throwable $e): string
    {
        $lines = preg_split('/\r?\n/', $e->getMessage());
        $first = is_array($lines) && $lines !== [] ? $lines[0] : '';

        // Cap the surfaced message so a verbose IdP error cannot
        // contaminate a flash session payload.
        return substr($first, 0, 300);
    }
}
