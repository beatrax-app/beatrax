<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\AccessTokenWithEmail;
use Modules\EmailScan\Internal\OAuth\AuthorizationRequest;
use Modules\EmailScan\Internal\OAuth\InvalidStateException;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/*
 * OAuth callback (Microsoft 365) happy-path + state mismatch +
 * canceled-at-consent scenarios. The mock MicrosoftOAuthProvider
 * records the redirectUri argument so the test can prove the loopback
 * URI was computed server-side from app.url rather than smuggled from
 * the query string.
 */

beforeEach(function (): void {
    $this->path = storage_path('app/secrets/email-oauth.json');
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

afterEach(function (): void {
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

function ocmUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function ocmSeedProviderClient(OAuthSecretsRepository $secrets): void
{
    $secrets->saveProviderClient(
        'microsoft',
        '12345678-1234-4abc-89ab-123456789abc',
        'fake-microsoft-secret-value',
        'http://127.0.0.1:8000/oauth/callback/microsoft',
    );
}

it('OAuth callback (microsoft) happy path inserts inbox + scan_state + saves refresh_token + redirects with flash', function (): void {
    $user = ocmUser('bob@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocmSeedProviderClient($secrets);

    // Mock the MicrosoftOAuthProvider. Capture the redirectUri
    // argument so we can assert it was the loopback IP scheme computed
    // server-side, not a value smuggled from a query parameter.
    $fakeToken = new AccessTokenWithEmail(
        accessToken: 'fake-microsoft-access-token',
        refreshToken: 'fake-microsoft-refresh-token-67890',
        expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        scope: 'Mail.Read offline_access User.Read',
        email: 'bob@example.com',
    );

    $mock = new class($fakeToken) extends MicrosoftOAuthProvider
    {
        public ?string $lastRedirectUri = null;

        public function __construct(
            private readonly AccessTokenWithEmail $token,
        ) {
            // Skip parent constructor — we replace the surface.
        }

        public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
        {
            $this->lastRedirectUri = $redirectUri;

            return new AuthorizationRequest('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?state='.$state, '');
        }

        public function exchangeAuthorizationCode(string $code, string $redirectUri, string $pkceVerifier = ''): AccessTokenWithEmail
        {
            $this->lastRedirectUri = $redirectUri;

            return $this->token;
        }

        public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
        {
            return $this->token;
        }

        public function readEmail(string $accessToken): string
        {
            return $this->token->email;
        }
    };

    $this->app->instance(MicrosoftOAuthProvider::class, $mock);

    // Issue a state via the real OAuthStateRepository — the callback
    // controller consumes it via the same singleton-bound instance.
    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('microsoft', userId: $user->id);

    $response = $this->get('/oauth/callback/microsoft?state='.$state.'&code=fake-ms-code-abcdef');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('open_backfill_modal');

    $flashed = session('open_backfill_modal');
    expect(is_int($flashed) || is_numeric($flashed))->toBeTrue();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('inboxes')
        ->where('user_id', $user->id)
        ->where('provider', 'microsoft')
        ->first(['id', 'email']);
    expect($row)->not->toBeNull();
    expect($row->email)->toBe('bob@example.com');

    $scanRow = $db->connection()->table('inbox_scan_state')
        ->where('user_id', $user->id)
        ->where('inbox_id', $row->id)
        ->where('folder', 'INBOX')
        ->first(['status']);
    expect($scanRow)->not->toBeNull();
    expect($scanRow->status)->toBe('idle');

    $loaded = $secrets->loadInbox((int) $row->id);
    expect($loaded)->not->toBeNull();
    expect($loaded->refreshToken)->toBe('fake-microsoft-refresh-token-67890');
    expect($loaded->provider)->toBe('microsoft');

    // Assert the loopback redirect URI was computed server-side.
    $expectedPort = parse_url((string) config('app.url'), PHP_URL_PORT);
    if (! is_int($expectedPort) || $expectedPort <= 0) {
        $expectedPort = 8000;
    }
    expect($mock->lastRedirectUri)->toBe('http://127.0.0.1:'.$expectedPort.'/oauth/callback/microsoft');
});

it('OAuth callback (microsoft) with mismatched state raises InvalidStateException', function (): void {
    $user = ocmUser('mismatch-ms@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocmSeedProviderClient($secrets);

    $this->withoutExceptionHandling();

    expect(function (): void {
        $this->get('/oauth/callback/microsoft?state=not-issued&code=fake');
    })->toThrow(InvalidStateException::class);
});

it('OAuth callback (microsoft) with provider error (user canceled at consent) redirects with oauth_canceled flash and inserts no rows', function (): void {
    $user = ocmUser('canceled-ms@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocmSeedProviderClient($secrets);

    $response = $this->get('/oauth/callback/microsoft?error=access_denied&error_description=user%20denied');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('oauth_canceled');

    $flashed = session('oauth_canceled');
    expect($flashed)->toContain('user denied');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $count = $db->connection()->table('inboxes')->where('user_id', $user->id)->count();
    expect($count)->toBe(0);
});
