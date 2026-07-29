<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Modules\EmailScan\Internal\SafeMessage;
use Modules\EmailScan\Public\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use RuntimeException;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
class GoogleOAuthProvider
{
    private const GMAIL_READONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private const USERINFO_EMAIL_SCOPE = 'https://www.googleapis.com/auth/userinfo.email';

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly OAuthProviderFactory $providers,
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
            // Persists the full scope set asked for (not just
            // gmail.readonly), so a later out-of-band revoke of
            // userinfo surfaces as needs_reauth, not a generic failure.
            scope: self::GMAIL_READONLY_SCOPE.' '.self::USERINFO_EMAIL_SCOPE,
            email: $email,
        );
    }

    public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
    {
        // Refresh exchanges don't strictly need a redirect URI, but
        // the league provider still validates it against Google's
        // allow-list, so the configured one is reused here.
        $client = $this->secrets->loadProviderClient('gmail');
        if ($client === null) {
            throw new InboxNotConfiguredException(
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
            // Mirrors exchangeAuthorizationCode's full scope string so
            // the recorded value stays consistent across refreshes.
            scope: self::GMAIL_READONLY_SCOPE.' '.self::USERINFO_EMAIL_SCOPE,
            email: '',
        );
    }

    public function readEmail(string $accessToken): string
    {
        $client = $this->secrets->loadProviderClient('gmail');
        if ($client === null) {
            throw new InboxNotConfiguredException(
                'Google OAuth client is not configured — run the OAuth-client wizard first.',
            );
        }
        $provider = $this->makeProvider($client['redirect_uri']);

        return $this->readEmailFromToken($provider, $accessToken);
    }

    private function makeProvider(string $redirectUri): Google
    {
        return $this->providers->google($redirectUri);
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
