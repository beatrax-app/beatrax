<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\AccessTokenWithEmail;
use Modules\EmailScan\Internal\OAuth\AuthorizationRequest;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\InvalidStateException;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * OAuth callback (Gmail) happy-path + state mismatch + canceled-at-
 * consent scenarios. The mock GoogleOAuthProvider records the
 * redirectUri argument so the test can prove the loopback URI was
 * computed server-side from app.url rather than smuggled from the
 * query string.
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

function ocgUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function ocgSeedProviderClient(OAuthSecretsRepository $secrets): void
{
    $secrets->saveProviderClient(
        'gmail',
        'fake-client-id.apps.googleusercontent.com',
        'GOCSPX-fake-secret',
        'http://127.0.0.1:8000/oauth/callback/gmail',
    );
}

it('OAuth callback (gmail) happy path inserts inbox + scan_state + saves refresh_token + redirects with flash', function (): void {
    $user = ocgUser('alice@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    // Mock the GoogleOAuthProvider. Capture the redirectUri argument
    // so we can assert it was the loopback IP scheme computed
    // server-side, not a value smuggled from a query parameter.
    $fakeToken = new AccessTokenWithEmail(
        accessToken: 'fake-access-token',
        refreshToken: 'fake-refresh-token-12345',
        expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        email: 'alice@example.com',
    );

    $mock = new class($fakeToken) extends GoogleOAuthProvider
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

            return new AuthorizationRequest('https://accounts.google.com/o/oauth2/auth?state='.$state, '');
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

    $this->app->instance(GoogleOAuthProvider::class, $mock);

    // Issue a state via the real OAuthStateRepository — the callback
    // controller consumes it via the same singleton-bound instance.
    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id);

    $response = $this->get('/oauth/callback/gmail?state='.$state.'&code=fake-code-abcdef');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('open_backfill_modal');

    $flashed = session('open_backfill_modal');
    expect(is_int($flashed) || is_numeric($flashed))->toBeTrue();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('inboxes')
        ->where('user_id', $user->id)
        ->where('provider', 'gmail')
        ->first(['id', 'email']);
    expect($row)->not->toBeNull();
    expect($row->email)->toBe('alice@example.com');

    $scanRow = $db->connection()->table('inbox_scan_state')
        ->where('user_id', $user->id)
        ->where('inbox_id', $row->id)
        ->where('folder', 'INBOX')
        ->first(['status']);
    expect($scanRow)->not->toBeNull();
    expect($scanRow->status)->toBe('idle');

    $loaded = $secrets->loadInbox((int) $row->id);
    expect($loaded)->not->toBeNull();
    expect($loaded->refreshToken)->toBe('fake-refresh-token-12345');

    // Assert the loopback redirect URI was computed server-side.
    $expectedPort = parse_url((string) config('app.url'), PHP_URL_PORT);
    if (! is_int($expectedPort) || $expectedPort <= 0) {
        $expectedPort = 8000;
    }
    expect($mock->lastRedirectUri)->toBe('http://127.0.0.1:'.$expectedPort.'/oauth/callback/gmail');
});

it('OAuth callback with mismatched state raises InvalidStateException', function (): void {
    $user = ocgUser('mismatch@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    $this->withoutExceptionHandling();

    expect(function (): void {
        $this->get('/oauth/callback/gmail?state=not-issued&code=fake');
    })->toThrow(InvalidStateException::class);
});

it('OAuth callback with provider error (user canceled at consent) redirects with oauth_canceled flash and inserts no rows', function (): void {
    $user = ocgUser('canceled@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    $response = $this->get('/oauth/callback/gmail?error=access_denied&error_description=user%20denied');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('oauth_canceled');

    $flashed = session('oauth_canceled');
    expect($flashed)->toContain('user denied');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $count = $db->connection()->table('inboxes')->where('user_id', $user->id)->count();
    expect($count)->toBe(0);
});

it('OAuth callback for unknown provider returns 404', function (): void {
    $user = ocgUser('unknown@example.com');
    $this->actingAs($user);

    // Suppress the default exception-handler render so the
    // NotFoundHttpException surfaces in the test as a thrown
    // exception we can match.
    $this->withoutExceptionHandling();

    expect(function (): void {
        $this->get('/oauth/callback/yahoo?state=x&code=y');
    })->toThrow(HttpException::class);
});

it('new-inbox callback without a refresh token rejects with flash and inserts no rows', function (): void {
    $user = ocgUser('noreftoken@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    // Provider returns NO refresh token (Google "Testing" mode case).
    $fakeToken = new AccessTokenWithEmail(
        accessToken: 'fake-access-token',
        refreshToken: null,
        expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        email: 'noreftoken@example.com',
    );

    $mock = new class($fakeToken) extends GoogleOAuthProvider
    {
        public function __construct(private readonly AccessTokenWithEmail $token)
        {
            // Skip parent constructor.
        }

        public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
        {
            return new AuthorizationRequest('https://accounts.google.com/?state='.$state, '');
        }

        public function exchangeAuthorizationCode(string $code, string $redirectUri, string $pkceVerifier = ''): AccessTokenWithEmail
        {
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

    $this->app->instance(GoogleOAuthProvider::class, $mock);

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id);

    $response = $this->get('/oauth/callback/gmail?state='.$state.'&code=fake');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('oauth_failed');
    $flashed = session('oauth_failed');
    expect($flashed)->toContain('refresh token');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $count = $db->connection()->table('inboxes')->where('user_id', $user->id)->count();
    expect($count)->toBe(0);
    $stateCount = $db->connection()->table('inbox_scan_state')->where('user_id', $user->id)->count();
    expect($stateCount)->toBe(0);
});

it('compensating rollback: secret-write failure deletes the just-inserted inbox + scan_state rows', function (): void {
    $user = ocgUser('rollback@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    $fakeToken = new AccessTokenWithEmail(
        accessToken: 'fake-access',
        refreshToken: 'fake-refresh',
        expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        email: 'rollback@example.com',
    );
    $mock = new class($fakeToken) extends GoogleOAuthProvider
    {
        public function __construct(private readonly AccessTokenWithEmail $token)
        {
            // Skip parent.
        }

        public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
        {
            return new AuthorizationRequest('https://accounts.google.com/?state='.$state, '');
        }

        public function exchangeAuthorizationCode(string $code, string $redirectUri, string $pkceVerifier = ''): AccessTokenWithEmail
        {
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
    $this->app->instance(GoogleOAuthProvider::class, $mock);

    // Substitute a secrets repository that throws on saveInboxRefreshToken
    // to exercise the compensating-rollback branch.
    $throwingSecrets = new class($secrets) extends OAuthSecretsRepository
    {
        public function __construct(private readonly OAuthSecretsRepository $real)
        {
            // Skip parent constructor — we delegate to the real one for
            // every method except the throw point.
        }

        public function hasProviderClient(string $provider): bool
        {
            return $this->real->hasProviderClient($provider);
        }

        public function saveProviderClient(string $provider, string $clientId, string $clientSecret, string $redirectUri): void
        {
            $this->real->saveProviderClient($provider, $clientId, $clientSecret, $redirectUri);
        }

        public function loadProviderClient(string $provider): ?array
        {
            return $this->real->loadProviderClient($provider);
        }

        public function loadInbox(int $inboxId): ?InboxCredentials
        {
            return $this->real->loadInbox($inboxId);
        }

        public function saveInboxRefreshToken(int $inboxId, string $provider, string $email, string $refreshToken, string $scope, ?DateTimeImmutable $expiresAt): void
        {
            throw new SecretsWriteFailed('simulated write failure');
        }

        public function rotateRefreshToken(int $inboxId, string $newRefreshToken, ?string $newAccessToken, ?DateTimeImmutable $expiresAt): void
        {
            $this->real->rotateRefreshToken($inboxId, $newRefreshToken, $newAccessToken, $expiresAt);
        }

        public function removeInbox(int $inboxId): void
        {
            $this->real->removeInbox($inboxId);
        }
    };
    $this->app->instance(OAuthSecretsRepository::class, $throwingSecrets);

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id);

    $response = $this->get('/oauth/callback/gmail?state='.$state.'&code=fake');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('oauth_failed');
    $flashed = session('oauth_failed');
    expect($flashed)->toContain('simulated write failure');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $count = $db->connection()->table('inboxes')->where('user_id', $user->id)->count();
    expect($count)->toBe(0);
    $stateCount = $db->connection()->table('inbox_scan_state')->where('user_id', $user->id)->count();
    expect($stateCount)->toBe(0);
});
