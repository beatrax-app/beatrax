<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Modules\EmailScan\Internal\SafeMessage;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper over League\OAuth2\Client\Provider\Google.
 *
 * Owns three concerns the rest of the module relies on:
 *
 *  1. Reading the per-install OAuth client id + secret out of the
 *     chmod-600 JSON repository on every call so the controller
 *     never holds credentials in memory across requests.
 *  2. Mapping the league library's IdentityProviderException to the
 *     module's two typed sentinels (InvalidGrantException for the
 *     needs_reauth transition; OAuthExchangeFailed for everything
 *     else) without ever including the raw token or request body in
 *     the message.
 *  3. Always issuing the authorize URL with access_type=offline +
 *     prompt=consent so Google issues a refresh token on every
 *     consent — required for the always-on background scanner.
 *
 * Per the project's DI invariant, the underlying Google provider is
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
class GoogleOAuthProvider
{
    private const GMAIL_READONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private const USERINFO_EMAIL_SCOPE = 'https://www.googleapis.com/auth/userinfo.email';

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
    ) {}

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $provider = $this->makeProvider($redirectUri);

        return $provider->getAuthorizationUrl([
            'scope' => [
                self::GMAIL_READONLY_SCOPE,
                self::USERINFO_EMAIL_SCOPE,
            ],
            'state' => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): AccessTokenWithEmail
    {
        $provider = $this->makeProvider($redirectUri);

        try {
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);
        } catch (IdentityProviderException $e) {
            throw $this->mapIdentityProviderException($e);
        } catch (Throwable $e) {
            throw new OAuthExchangeFailed(
                'Google OAuth token exchange failed: '.$this->safeMessage($e),
            );
        }

        $accessTokenString = $token->getToken();
        $email = $this->readEmailFromToken($provider, $accessTokenString);

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
            // Persist the FULL scope set we asked Google for so the
            // stored credential matches what was granted. Previously
            // we recorded only gmail.readonly and dropped
            // userinfo.email; a later out-of-band revoke of userinfo
            // surfaced as a generic OAuthExchangeFailed rather than
            // the more actionable needs_reauth signal.
            scope: self::GMAIL_READONLY_SCOPE.' '.self::USERINFO_EMAIL_SCOPE,
            email: $email,
        );
    }

    public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
    {
        // Refresh exchanges do not strictly need a redirect URI but
        // the league provider still validates the configured one
        // against Google's allow-list. We reuse the configured
        // redirect URI persisted alongside the client credentials.
        $client = $this->secrets->loadProviderClient('gmail');
        if ($client === null) {
            throw new RuntimeException(
                'Google OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }

        $provider = $this->makeProvider($client['redirect_uri']);

        try {
            $token = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
        } catch (IdentityProviderException $e) {
            throw $this->mapIdentityProviderException($e);
        } catch (Throwable $e) {
            throw new OAuthExchangeFailed(
                'Google OAuth refresh failed: '.$this->safeMessage($e),
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
            // Mirror exchangeAuthorizationCode: persist the full
            // gmail.readonly + userinfo.email scope so the recorded
            // value stays consistent across the initial exchange and
            // every subsequent refresh.
            scope: self::GMAIL_READONLY_SCOPE.' '.self::USERINFO_EMAIL_SCOPE,
            email: '',
        );
    }

    public function readEmail(string $accessToken): string
    {
        $client = $this->secrets->loadProviderClient('gmail');
        if ($client === null) {
            throw new RuntimeException(
                'Google OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }
        $provider = $this->makeProvider($client['redirect_uri']);

        return $this->readEmailFromToken($provider, $accessToken);
    }

    private function makeProvider(string $redirectUri): Google
    {
        $client = $this->secrets->loadProviderClient('gmail');
        if ($client === null) {
            throw new RuntimeException(
                'Google OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }

        return new Google([
            'clientId' => $client['client_id'],
            'clientSecret' => $client['client_secret'],
            'redirectUri' => $redirectUri,
            'accessType' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    private function readEmailFromToken(Google $provider, string $accessToken): string
    {
        try {
            $tokenObj = new AccessToken([
                'access_token' => $accessToken,
            ]);
            $owner = $provider->getResourceOwner($tokenObj);
            // The Google provider always returns a GoogleUser; the
            // narrower instanceof check keeps the strict static
            // analyser honest about the getEmail() call below.
            if (! $owner instanceof GoogleUser) {
                throw new OAuthExchangeFailed(
                    'Google userinfo response was not a GoogleUser.',
                );
            }
            $email = $owner->getEmail();

            return is_string($email) ? $email : '';
        } catch (OAuthExchangeFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OAuthExchangeFailed(
                'Google userinfo read failed: '.$this->safeMessage($e),
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
                'Google OAuth refresh rejected with invalid_grant — reconnect required.',
            );
        }

        return new OAuthExchangeFailed('Google OAuth exchange failed: '.$message);
    }

    private function safeMessage(Throwable $e): string
    {
        // Delegate to the shared utility so the cap shape stays
        // consistent across every module-internal surface that
        // forwards provider error text.
        return SafeMessage::cap($e->getMessage());
    }
}
