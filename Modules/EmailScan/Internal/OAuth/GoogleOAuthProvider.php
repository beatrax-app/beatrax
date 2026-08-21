<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Modules\EmailScan\Internal\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Internal\SafeMessage;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use RuntimeException;
use Throwable;

class GoogleOAuthProvider
{
    private const GMAIL_READONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private const USERINFO_EMAIL_SCOPE = 'https://www.googleapis.com/auth/userinfo.email';

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly OAuthProviderFactory $providers,
    ) {}

    public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
    {
        $provider = $this->makeProvider($redirectUri);

        $url = $provider->getAuthorizationUrl([
            'scope' => [
                self::GMAIL_READONLY_SCOPE,
                self::USERINFO_EMAIL_SCOPE,
            ],
            'state' => $state,
        ]);

        // The league provider mints the PKCE verifier while building the URL;
        // capturing it lets the callback send code_verifier on the exchange,
        // which is what makes an intercepted loopback code useless alone.
        $verifier = $provider->getPkceCode();

        return new AuthorizationRequest($url, is_string($verifier) ? $verifier : '');
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri, string $pkceVerifier = ''): AccessTokenWithEmail
    {
        $provider = $this->makeProvider($redirectUri);

        if ($pkceVerifier !== '') {
            $provider->setPkceCode($pkceVerifier);
        }

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
            // The full scope set, not just gmail.readonly: an out-of-band
            // revoke of userinfo then surfaces as needs_reauth.
            scope: self::GMAIL_READONLY_SCOPE.' '.self::USERINFO_EMAIL_SCOPE,
            email: $email,
        );
    }

    public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
    {
        // A refresh needs no redirect URI, but the league provider still
        // validates one against Google's allow-list.
        $client = $this->secrets->loadProviderClient(MailProvider::Gmail->value);
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
            // Mirrors exchangeAuthorizationCode so the recorded scope string
            // stays identical across refreshes.
            scope: self::GMAIL_READONLY_SCOPE.' '.self::USERINFO_EMAIL_SCOPE,
            email: '',
        );
    }

    public function readEmail(string $accessToken): string
    {
        $client = $this->secrets->loadProviderClient(MailProvider::Gmail->value);
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
        return SafeMessage::cap($e->getMessage());
    }
}
