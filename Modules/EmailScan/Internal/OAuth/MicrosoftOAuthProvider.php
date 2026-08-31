<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Modules\EmailScan\Internal\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Internal\SafeMessage;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use RuntimeException;
use TheNetworg\OAuth2\Client\Provider\Azure;
use TheNetworg\OAuth2\Client\Token\AccessToken as AzureAccessToken;
use Throwable;

class MicrosoftOAuthProvider
{
    private const string MAIL_READ_SCOPE = 'Mail.Read';

    private const string OFFLINE_ACCESS_SCOPE = 'offline_access';

    private const string USER_READ_SCOPE = 'User.Read';

    private const string SCOPE_STRING = self::MAIL_READ_SCOPE.' '.self::OFFLINE_ACCESS_SCOPE.' '.self::USER_READ_SCOPE;

    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly OAuthProviderFactory $providers,
    ) {}

    public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
    {
        $provider = $this->makeProvider($redirectUri);

        $url = $provider->getAuthorizationUrl([
            'scope' => [
                self::MAIL_READ_SCOPE,
                self::OFFLINE_ACCESS_SCOPE,
                self::USER_READ_SCOPE,
            ],
            'state' => $state,
            'prompt' => 'consent',
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
                'scope' => self::SCOPE_STRING,
            ]);
        } catch (IdentityProviderException $e) {
            throw $this->mapIdentityProviderException($e);
        } catch (Throwable $e) {
            throw new OAuthExchangeFailed(
                'Microsoft OAuth token exchange failed: '.$this->safeMessage($e),
                previous: $e,
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
            scope: self::grantedScope($token),
            email: $email,
        );
    }

    public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
    {
        $client = $this->secrets->loadProviderClient(MailProvider::Microsoft->value);
        if ($client === null) {
            throw new InboxNotConfiguredException(
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
                previous: $e,
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
            scope: self::grantedScope($token),
            email: '',
        );
    }

    public function readEmail(string $accessToken): string
    {
        $client = $this->secrets->loadProviderClient(MailProvider::Microsoft->value);
        if ($client === null) {
            throw new InboxNotConfiguredException(
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
        return $this->providers->microsoft($redirectUri);
    }

    private function readEmailFromToken(Azure $provider, AccessTokenInterface $token): string
    {
        try {
            // The Azure provider's get() takes the token by reference so it
            // can auto-rotate mid-call; a local keeps those reference
            // semantics off the caller-supplied AccessTokenWithEmail.
            $tokenRef = $token;
            $response = $provider->get('https://graph.microsoft.com/v1.0/me', $tokenRef);

            if (! is_array($response)) {
                throw new OAuthExchangeFailed(
                    'Microsoft Graph /me response was not an associative array.',
                );
            }

            // For consumer Outlook.com accounts `mail` is often null and
            // `userPrincipalName` holds the routable address instead.
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
                previous: $e,
            );
        }
    }

    private function mapIdentityProviderException(IdentityProviderException $e): RuntimeException
    {
        $message = $this->safeMessage($e);

        if (str_contains($message, 'invalid_grant') || self::bodyError($e) === 'invalid_grant') {
            return new InvalidGrantException(
                'Microsoft OAuth refresh rejected with invalid_grant — reconnect required.',
            );
        }

        return new OAuthExchangeFailed('Microsoft OAuth exchange failed: '.$message);
    }

    // The league Azure provider passes a PSR-7 stream here, never an array, so
    // the old is_array() arm was dead. It went unnoticed because a flat
    // {"error":"invalid_grant"} body repeats the code in the message — Azure's
    // nested {"error":{"code":…}} shape does not.
    private static function bodyError(IdentityProviderException $e): string
    {
        $body = $e->getResponseBody();
        $raw = is_array($body) ? $body : self::decodeBody($body);

        $error = $raw['error'] ?? null;
        if (is_string($error)) {
            return $error;
        }

        $code = is_array($error) ? ($error['code'] ?? null) : null;

        return is_string($code) ? $code : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeBody(mixed $body): array
    {
        $text = is_string($body) ? $body : (is_object($body) && method_exists($body, '__toString') ? (string) $body : '');
        if ($text === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }

    // What the user actually consented to, never what was asked for: a scope
    // unticked on the consent screen otherwise leaves an inbox the app
    // believes can read mail, and the first scan 403s into a generic error
    // rather than the actionable needs_reauth.
    private static function grantedScope(AccessTokenInterface $token): string
    {
        $granted = $token->getValues()['scope'] ?? null;

        return is_string($granted) && $granted !== '' ? $granted : self::SCOPE_STRING;
    }

    private function safeMessage(Throwable $e): string
    {
        return SafeMessage::cap($e->getMessage());
    }
}
