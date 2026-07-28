<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

uses(RefreshDatabase::class);

/**
 * The authorization URL is the one part of the OAuth dance this application
 * builds itself, and it carries two things worth pinning: the CSRF state, and
 * the scopes the user is asked to grant. Both are built offline, so they can be
 * asserted without reaching Google or Microsoft.
 */
beforeEach(function (): void {
    // The secrets repository is user-scoped: it resolves the owner from the
    // guard rather than taking one, so there has to be a signed-in user before
    // a client can be stored or read at all.
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->secrets = app(OAuthSecretsRepository::class);
    $this->redirect = 'http://127.0.0.1:8000/oauth/callback';
});

it('asks Google for read-only mail and nothing wider', function (): void {
    $this->secrets->saveProviderClient('gmail', 'google-client', 'google-secret', $this->redirect);

    $url = app(GoogleOAuthProvider::class)->getAuthorizationUrl('state-token-123', $this->redirect);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    // The League provider prepends its own openid/email/profile defaults, so
    // this asserts what was asked for and — the part that matters — what was
    // not: any of these would let the app alter or send the user's mail.
    expect($params['scope'])->toContain('https://www.googleapis.com/auth/gmail.readonly')
        ->and($params['scope'])->toContain('https://www.googleapis.com/auth/userinfo.email')
        ->and($params['scope'])->not->toContain('gmail.modify')
        ->and($params['scope'])->not->toContain('gmail.send')
        ->and($params['scope'])->not->toContain('gmail.compose')
        ->and($params['scope'])->not->toContain('https://mail.google.com/')
        ->and($params['state'])->toBe('state-token-123')
        ->and($params['client_id'])->toBe('google-client')
        ->and($params['redirect_uri'])->toBe($this->redirect);
});

it('asks Google for a refresh token, which only offline consent returns', function (): void {
    $this->secrets->saveProviderClient('gmail', 'google-client', 'google-secret', $this->redirect);

    $url = app(GoogleOAuthProvider::class)->getAuthorizationUrl('s', $this->redirect);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    // Without both of these Google returns an access token only, and the
    // inbox stops scanning an hour later with no way to re-authorise silently.
    expect($params['access_type'])->toBe('offline')
        ->and($params['prompt'])->toBe('consent');
});

// There is no equivalent test for Microsoft's authorization URL. The Azure
// provider resolves tenant metadata over the network before it will build one,
// so the URL cannot be produced offline the way Google's can. Asserting on it
// here would either need the OpenID discovery document faked through a Guzzle
// client the provider does not expose, or would quietly depend on the runner
// having internet — a test that passes for the wrong reason. Its guard path is
// covered below.

it('carries the state through verbatim, since it is the CSRF check', function (string $state): void {
    $this->secrets->saveProviderClient('gmail', 'google-client', 'google-secret', $this->redirect);

    $url = app(GoogleOAuthProvider::class)->getAuthorizationUrl($state, $this->redirect);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    expect($params['state'])->toBe($state);
})->with([
    'hex' => ['9f8e7d6c5b4a39281706'],
    'with padding' => ['abc=='],
    'url unsafe' => ['a+b/c=='],
]);

it('refuses to start the dance before the OAuth client is configured', function (string $provider): void {
    app($provider)->getAuthorizationUrl('s', 'http://127.0.0.1:8000/cb');
})->with([
    'google' => [GoogleOAuthProvider::class],
    'microsoft' => [MicrosoftOAuthProvider::class],
])->throws(RuntimeException::class, 'is not configured');

it('does not leak the client secret into the authorization URL', function (): void {
    $this->secrets->saveProviderClient('gmail', 'google-client', 'super-secret-value', $this->redirect);

    $url = app(GoogleOAuthProvider::class)->getAuthorizationUrl('s', $this->redirect);

    // The URL goes to the user's browser and into their history; only the
    // client id belongs in it.
    expect($url)->not->toContain('super-secret-value');
});
