<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthExchangeFailed;
use Modules\EmailScan\Internal\OAuth\OAuthProviderFactory;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

uses(RefreshDatabase::class);

// Azure needs one mocked response more than Google: it resolves its endpoints
// from the tenant's OpenID configuration document before it will build a URL
// or post to a token endpoint, so even the authorization URL hits the network.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->redirect = 'http://127.0.0.1:8000/oauth/callback';
    app(OAuthSecretsRepository::class)
        ->saveProviderClient('microsoft', 'ms-client', 'ms-secret', $this->redirect);
});

/**
 * @param  array<int, Response>  $responses
 */
function microsoftProviderReturning(array $responses): MicrosoftOAuthProvider
{
    $stack = HandlerStack::create(new MockHandler($responses));

    $factory = new OAuthProviderFactory(
        app(OAuthSecretsRepository::class),
        new GuzzleClient(['handler' => $stack]),
    );

    return new MicrosoftOAuthProvider(app(OAuthSecretsRepository::class), $factory);
}

// Cached per tenant, so it has to be the first queued response every time.
function azureOpenIdConfigResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0',
        'authorization_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'end_session_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/logout',
        'jwks_uri' => 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
    ]));
}

function azureTokenResponse(array $overrides = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(array_merge([
        'access_token' => 'ms-access-token',
        'refresh_token' => 'ms-refresh-token',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
        'scope' => 'Mail.Read offline_access User.Read',
    ], $overrides)));
}

function azureMeResponse(string $email = 'owner@contoso.com'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'id' => '00000000-0000-0000-0000-000000000001',
        'userPrincipalName' => $email,
        'mail' => $email,
        'displayName' => 'Inbox Owner',
    ]));
}

it('asks Microsoft for mail read plus the offline access a refresh needs', function (): void {
    $provider = microsoftProviderReturning([azureOpenIdConfigResponse()]);

    $url = $provider->getAuthorizationUrl('state-token-456', $this->redirect)->url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    expect($params['scope'])->toContain('Mail.Read')
        ->and($params['scope'])->toContain('offline_access')
        ->and($params['scope'])->toContain('User.Read')
        // Mail.ReadWrite and Mail.Send would let the app alter or send mail;
        // this integration only ever reads.
        ->and($params['scope'])->not->toContain('Mail.ReadWrite')
        ->and($params['scope'])->not->toContain('Mail.Send')
        ->and($params['state'])->toBe('state-token-456');
});

it('exchanges an authorization code for a token and the mailbox it belongs to', function (): void {
    $provider = microsoftProviderReturning([
        azureOpenIdConfigResponse(),
        azureTokenResponse(),
        azureMeResponse('inbox-owner@contoso.com'),
    ]);

    $result = $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);

    expect($result->accessToken)->toBe('ms-access-token')
        ->and($result->refreshToken)->toBe('ms-refresh-token')
        ->and($result->email)->toBe('inbox-owner@contoso.com')
        ->and($result->expiresAt)->not->toBeNull();
});

it('refreshes an access token without a fresh authorization', function (): void {
    $provider = microsoftProviderReturning([
        azureOpenIdConfigResponse(),
        azureTokenResponse(['access_token' => 'ms-rotated-token']),
        azureMeResponse(),
    ]);

    $result = $provider->refreshAccessToken('ms-refresh-token');

    expect($result->accessToken)->toBe('ms-rotated-token');
});

it('reports a rejected refresh as needing reconsent', function (): void {
    // invalid_grant is the failure no retry can fix; treating it as transient
    // leaves an inbox looping on a token that will never work again.
    $provider = microsoftProviderReturning([
        azureOpenIdConfigResponse(),
        new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'AADSTS70000: The refresh token has expired.',
        ])),
    ]);

    $provider->refreshAccessToken('revoked-refresh-token');
})->throws(InvalidGrantException::class);

it('reports any other provider rejection as an exchange failure', function (): void {
    $provider = microsoftProviderReturning([
        azureOpenIdConfigResponse(),
        new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => 'unauthorized_client',
            'error_description' => 'AADSTS700016: Application not found in directory.',
        ])),
    ]);

    $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);
})->throws(OAuthExchangeFailed::class);

// Recording the requested scope instead of the granted one leaves an inbox
// the app believes can read mail; the first scan 403s into a generic error
// rather than the actionable needs_reauth.
it('records the scope Microsoft granted, not the one that was requested', function (): void {
    $provider = microsoftProviderReturning([
        azureOpenIdConfigResponse(),
        azureTokenResponse(['scope' => 'offline_access User.Read']),
        azureMeResponse(),
    ]);

    $result = $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);

    expect($result->scope)->toBe('offline_access User.Read');
});

it('falls back to the requested scope when Microsoft omits one', function (): void {
    $provider = microsoftProviderReturning([
        azureOpenIdConfigResponse(),
        azureTokenResponse(['scope' => null]),
        azureMeResponse(),
    ]);

    $result = $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);

    expect($result->scope)->toBe('Mail.Read offline_access User.Read');
});

// The library hands the error body over as a PSR-7 stream, never an array, so
// the is_array() arm never ran. It went unnoticed because the flat
// {"error":"invalid_grant"} body puts the same string in the message. Azure's
// nested shape does not, and that one fell through to a retryable failure.
it('reads invalid_grant out of a nested error body the message does not name', function (): void {
    $provider = microsoftProviderReturning([
        azureOpenIdConfigResponse(),
        new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => [
                'code' => 'invalid_grant',
                'message' => 'The refresh token has expired.',
            ],
        ])),
    ]);

    $provider->refreshAccessToken('revoked-refresh-token');
})->throws(InvalidGrantException::class);
