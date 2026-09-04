<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\OAuth\AccessTokenWithEmail;
use Modules\EmailScan\Internal\OAuth\AuthorizationRequest;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

// Records the redirectUri so a caller can prove it was computed server-side
// rather than smuggled in through a query parameter.
function ocgTokenProvider(?AccessTokenWithEmail $token = null): GoogleOAuthProvider
{
    return new class($token ?? new AccessTokenWithEmail(accessToken: 'fake-access-token', refreshToken: 'fake-refresh-token-12345', expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'), scope: 'https://www.googleapis.com/auth/gmail.readonly', email: 'fixture@example.com')) extends GoogleOAuthProvider
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
}

it('OAuth callback (gmail) happy path inserts inbox + scan_state + saves refresh_token + redirects with flash', function (): void {
    $user = ocgUser('alice@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    $fakeToken = new AccessTokenWithEmail(
        accessToken: 'fake-access-token',
        refreshToken: 'fake-refresh-token-12345',
        expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        email: 'alice@example.com',
    );

    $mock = ocgTokenProvider($fakeToken);

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

    // The port is derived from app.url, which is what makes the redirect URI
    // server-side rather than caller-supplied.
    $expectedPort = parse_url((string) config('app.url'), PHP_URL_PORT);
    if (! is_int($expectedPort) || $expectedPort <= 0) {
        $expectedPort = 8000;
    }
    expect($mock->lastRedirectUri)->toBe('http://127.0.0.1:'.$expectedPort.'/oauth/callback/gmail');
});

it('OAuth callback with mismatched state sends the reader back with a reason, not a server fault', function (): void {
    $user = ocgUser('mismatch@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    $response = $this->get('/oauth/callback/gmail?state=not-issued&code=fake');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('oauth_failed', Lang::get('email-scan::inboxes.oauth_state_mismatch'));
});

it('a state already spent answers the second press the same way as a forged one', function (): void {
    $user = ocgUser('replay@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id);
    $this->get('/oauth/callback/gmail?state='.$state.'&code=fake');

    $replayed = $this->get('/oauth/callback/gmail?state='.$state.'&code=fake');

    $replayed->assertRedirect(route('inboxes.index'));
    $replayed->assertSessionHas('oauth_failed', Lang::get('email-scan::inboxes.oauth_state_mismatch'));
});

it('a callback whose provider client has gone answers the reader, not the handler', function (): void {
    $user = ocgUser('clientgone@example.com');
    $this->actingAs($user);

    // No provider client is seeded: the wizard's row is what a connect
    // requires and this callback never checked for, so a secrets store that
    // lost it between the two halves of one flow raised out of the action.
    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id);

    $response = $this->get('/oauth/callback/gmail?state='.$state.'&code=fake-code');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('oauth_failed', Lang::get('email-scan::inboxes.oauth_not_saved'));
});

it('keeps the not-found a reconnect raises for an inbox the reader does not have', function (): void {
    $user = ocgUser('goneinbox@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    ocgSeedProviderClient($secrets);
    $this->app->instance(GoogleOAuthProvider::class, ocgTokenProvider());

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id, existingInboxId: 999999);

    $this->get('/oauth/callback/gmail?state='.$state.'&code=fake-code')->assertNotFound();
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
    expect($flashed)->toBe(Lang::get('email-scan::inboxes.oauth_no_offline_access_google'));

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
            // Skip parent constructor — every method delegates to the real one.
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
    expect($flashed)->toBe(Lang::get('email-scan::inboxes.oauth_not_saved'))
        ->and($flashed)->not->toContain('simulated write failure');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $count = $db->connection()->table('inboxes')->where('user_id', $user->id)->count();
    expect($count)->toBe(0);
    $stateCount = $db->connection()->table('inbox_scan_state')->where('user_id', $user->id)->count();
    expect($stateCount)->toBe(0);
});
