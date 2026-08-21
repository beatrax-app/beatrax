<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\AuthorizationRequest;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

// A cross-provider reconnect must 404: allowing it would write a Gmail refresh
// token under a provider='microsoft' row and break every IncrementalScanJob
// that followed.

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

function occUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function occSeedBothProviderClients(OAuthSecretsRepository $secrets): void
{
    $secrets->saveProviderClient(
        'gmail',
        'fake-client-id.apps.googleusercontent.com',
        'GOCSPX-fake-secret',
        'http://127.0.0.1:8000/oauth/callback/gmail',
    );
    $secrets->saveProviderClient(
        'microsoft',
        '11111111-1111-4111-8111-111111111111',
        'fake-azure-secret',
        'http://127.0.0.1:8000/oauth/callback/microsoft',
    );
}

function occSeedInbox(DatabaseManager $db, User $user, string $provider): int
{
    $now = now()->toDateTimeString();
    $id = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => $provider,
        'email' => $provider.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $id,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/**
 * @return array{0: GoogleOAuthProvider, 1: MicrosoftOAuthProvider}
 */
function occMakeOAuthMocks(): array
{
    $gmail = new class extends GoogleOAuthProvider
    {
        public function __construct() {}

        public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
        {
            return new AuthorizationRequest('https://accounts.google.com/o/oauth2/auth?state='.$state, '');
        }
    };
    $microsoft = new class extends MicrosoftOAuthProvider
    {
        public function __construct() {}

        public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
        {
            return new AuthorizationRequest('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?state='.$state, '');
        }
    };

    return [$gmail, $microsoft];
}

it('refuses a Gmail reconnect on a Microsoft inbox row (cross-provider rebind 404)', function (): void {
    $user = occUser('cross-provider@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    occSeedBothProviderClients($secrets);
    [$gmailMock, $microsoftMock] = occMakeOAuthMocks();
    $this->app->instance(GoogleOAuthProvider::class, $gmailMock);
    $this->app->instance(MicrosoftOAuthProvider::class, $microsoftMock);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $microsoftInboxId = occSeedInbox($db, $user, 'microsoft');

    // The Gmail consent dance aimed at a Microsoft-provider row.
    $response = $this->get('/oauth/connect/gmail?inbox_id='.$microsoftInboxId);

    // The same 404 the cross-user case gives, so which mismatch tripped the
    // guard cannot be told apart from outside.
    $response->assertNotFound();
});

it('refuses a Microsoft reconnect on a Gmail inbox row (cross-provider rebind 404)', function (): void {
    $user = occUser('cross-provider-2@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    occSeedBothProviderClients($secrets);
    [$gmailMock, $microsoftMock] = occMakeOAuthMocks();
    $this->app->instance(GoogleOAuthProvider::class, $gmailMock);
    $this->app->instance(MicrosoftOAuthProvider::class, $microsoftMock);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $gmailInboxId = occSeedInbox($db, $user, 'gmail');

    $response = $this->get('/oauth/connect/microsoft?inbox_id='.$gmailInboxId);
    $response->assertNotFound();
});

it('allows a same-provider reconnect (happy path) to proceed into consent', function (): void {
    $user = occUser('same-provider@example.com');
    $this->actingAs($user);

    $secrets = $this->app->make(OAuthSecretsRepository::class);
    occSeedBothProviderClients($secrets);
    [$gmailMock, $microsoftMock] = occMakeOAuthMocks();
    $this->app->instance(GoogleOAuthProvider::class, $gmailMock);
    $this->app->instance(MicrosoftOAuthProvider::class, $microsoftMock);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $microsoftInboxId = occSeedInbox($db, $user, 'microsoft');

    $response = $this->get('/oauth/connect/microsoft?inbox_id='.$microsoftInboxId);
    $response->assertStatus(302);
    expect($response->headers->get('Location'))
        ->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?state=');
});

it("returns 404 when reconnect targets another user's inbox", function (): void {
    $user = occUser('owner@example.com');
    $other = occUser('other@example.com');

    // The secrets store is per-user: no provider client can be saved before
    // someone is bound to the guard.
    $this->actingAs($user);
    $secrets = $this->app->make(OAuthSecretsRepository::class);
    occSeedBothProviderClients($secrets);
    [$gmailMock, $microsoftMock] = occMakeOAuthMocks();
    $this->app->instance(GoogleOAuthProvider::class, $gmailMock);
    $this->app->instance(MicrosoftOAuthProvider::class, $microsoftMock);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $otherInboxId = occSeedInbox($db, $other, 'microsoft');

    $response = $this->get('/oauth/connect/microsoft?inbox_id='.$otherInboxId);
    $response->assertNotFound();
});
