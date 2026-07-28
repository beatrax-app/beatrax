<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\OAuthExchangeFailed;
use Modules\EmailScan\Internal\OAuth\OAuthProviderFactory;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

uses(RefreshDatabase::class);

/**
 * The token exchange and refresh, driven through a mocked transport.
 *
 * These paths were unreachable until OAuthProviderFactory took over building
 * the League provider: the HTTP client is a collaborator League accepts, and
 * the factory is the only place that can hand it one. What is exercised here
 * is the part this codebase owns — reading the token, resolving the account
 * email, and deciding whether a failure means "retry" or "the user must
 * reconnect".
 */
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->redirect = 'http://127.0.0.1:8000/oauth/callback';
    app(OAuthSecretsRepository::class)
        ->saveProviderClient('gmail', 'google-client', 'google-secret', $this->redirect);
});

/**
 * Binds a GoogleOAuthProvider whose League provider answers from the queue
 * rather than from Google.
 *
 * @param  array<int, Response>  $responses
 */
function googleProviderReturning(array $responses): GoogleOAuthProvider
{
    $stack = HandlerStack::create(new MockHandler($responses));

    $factory = new OAuthProviderFactory(
        app(OAuthSecretsRepository::class),
        new GuzzleClient(['handler' => $stack]),
    );

    return new GoogleOAuthProvider(app(OAuthSecretsRepository::class), $factory);
}

function googleTokenResponse(array $overrides = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(array_merge([
        'access_token' => 'access-token-abc',
        'refresh_token' => 'refresh-token-xyz',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ], $overrides)));
}

function googleUserinfoResponse(string $email = 'owner@example.com'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'sub' => '110000000000000000001',
        'email' => $email,
        'email_verified' => true,
    ]));
}

it('exchanges an authorization code for a token and the account it belongs to', function (): void {
    $provider = googleProviderReturning([
        googleTokenResponse(),
        googleUserinfoResponse('inbox-owner@example.com'),
    ]);

    $result = $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);

    expect($result->accessToken)->toBe('access-token-abc')
        ->and($result->refreshToken)->toBe('refresh-token-xyz')
        ->and($result->email)->toBe('inbox-owner@example.com')
        ->and($result->expiresAt)->not->toBeNull();
});

it('records no expiry when the provider does not give one', function (): void {
    $provider = googleProviderReturning([
        googleTokenResponse(['expires_in' => null]),
        googleUserinfoResponse(),
    ]);

    $result = $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);

    expect($result->expiresAt)->toBeNull();
});

it('refreshes an access token without needing a fresh authorization', function (): void {
    $provider = googleProviderReturning([
        googleTokenResponse(['access_token' => 'rotated-token']),
    ]);

    $result = $provider->refreshAccessToken('refresh-token-xyz');

    expect($result->accessToken)->toBe('rotated-token');
});

it('reports a rejected refresh as needing reconsent, not as a transient failure', function (): void {
    // invalid_grant is the one refresh failure a retry can never fix: the user
    // revoked access or changed their password, and the only way forward is a
    // new consent. Mapping it to the same exception as a 500 would leave the
    // inbox retrying a token that will never work again.
    $provider = googleProviderReturning([
        new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ])),
    ]);

    $provider->refreshAccessToken('revoked-refresh-token');
})->throws(InvalidGrantException::class);

it('reports any other provider rejection as an exchange failure', function (): void {
    $provider = googleProviderReturning([
        new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => 'invalid_client',
            'error_description' => 'The OAuth client was not found.',
        ])),
    ]);

    $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);
})->throws(OAuthExchangeFailed::class);

it('fails the exchange when the account email cannot be read', function (): void {
    $provider = googleProviderReturning([
        googleTokenResponse(),
        new Response(500, ['Content-Type' => 'application/json'], '{"error":"backend error"}'),
    ]);

    // An inbox is keyed by the address it scans, so a token without a
    // resolvable owner is not something to store and sort out later.
    $provider->exchangeAuthorizationCode('auth-code-123', $this->redirect);
})->throws(OAuthExchangeFailed::class, 'Google userinfo read failed');

it('reads the account email from a standalone access token', function (): void {
    $provider = googleProviderReturning([
        googleUserinfoResponse('someone@example.com'),
    ]);

    expect($provider->readEmail('access-token-abc'))->toBe('someone@example.com');
});
