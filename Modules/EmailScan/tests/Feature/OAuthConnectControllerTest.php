<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\AuthorizationRequest;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/*
 * OAuthConnectController scenarios.
 *
 * Covers:
 *  - cross-provider reconnect rebind (CR-02 iter-2 regression):
 *    /oauth/connect/gmail?inbox_id={microsoft_inbox_id} must 404.
 *    Allowing it would write a Gmail refresh token under a
 *    provider='microsoft' row and break every subsequent
 *    IncrementalScanJob.
 *  - the happy-path same-provider reconnect: /oauth/connect/microsoft
 *    ?inbox_id={microsoft_inbox_id} proceeds normally.
 *  - cross-user reconnect: 404 (existing invariant — guard test
 *    reaffirms the cross-provider 404 does not regress the cross-user
 *    behaviour).
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
 * Returns the two stub provider instances the tests bind into the
 * container. Both override only `getAuthorizationUrl` — the controller
 * is the unit under test, not the providers.
 *
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

    // Crafted URL: Gmail consent dance for a Microsoft-provider row.
    $response = $this->get('/oauth/connect/gmail?inbox_id='.$microsoftInboxId);

    // Must respond with 404 — the same shape the cross-user case
    // already used, so an attacker cannot enumerate which mismatch
    // (cross-user vs cross-provider) tripped the guard.
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
    // 302 to the stubbed Microsoft consent URL.
    $response->assertStatus(302);
    expect($response->headers->get('Location'))
        ->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?state=');
});

it("returns 404 when reconnect targets another user's inbox", function (): void {
    $user = occUser('owner@example.com');
    $other = occUser('other@example.com');

    // The per-user OAuth secrets store requires an authenticated user
    // before a provider client is saved.
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
