<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

// The repository scopes on CurrentUser, so every case needs a real
// authenticated user for the scoping to resolve a concrete id.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'oauth-repo-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('reports no provider client on a virgin state', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    expect($repo->hasProviderClient('gmail'))->toBeFalse();
    expect($repo->hasProviderClient('microsoft'))->toBeFalse();
});

it('round-trips saveProviderClient + hasProviderClient + loadProviderClient', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveProviderClient('gmail', 'client-id-1', 'shh', 'http://127.0.0.1:8000/cb');
    expect($repo->hasProviderClient('gmail'))->toBeTrue();

    $loaded = $repo->loadProviderClient('gmail');
    expect($loaded)->not->toBeNull();
    expect($loaded['client_id'])->toBe('client-id-1');
    expect($loaded['client_secret'])->toBe('shh');
    expect($loaded['redirect_uri'])->toBe('http://127.0.0.1:8000/cb');
});

it('loadProviderClient returns null before any save', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    expect($repo->loadProviderClient('gmail'))->toBeNull();
});

it('saveProviderClient overwrites an existing provider row', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveProviderClient('gmail', 'id-a', 'secret-a', 'http://127.0.0.1/a');
    $repo->saveProviderClient('gmail', 'id-b', 'secret-b', 'http://127.0.0.1/b');

    $loaded = $repo->loadProviderClient('gmail');
    expect($loaded['client_id'])->toBe('id-b');
    expect($loaded['client_secret'])->toBe('secret-b');
    expect($loaded['redirect_uri'])->toBe('http://127.0.0.1/b');

    $rows = $this->db->connection()->table('oauth_secrets')
        ->where('user_id', $this->user->id)->where('provider', 'gmail')->count();
    expect($rows)->toBe(1);
});

it('stores client_secret as ciphertext that never contains the plaintext', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveProviderClient('gmail', 'id', 'plaintext-secret-value', 'http://127.0.0.1/cb');

    $cipher = $this->db->connection()
        ->table('oauth_secrets')
        ->where('user_id', $this->user->id)
        ->where('provider', 'gmail')
        ->value('client_secret');

    expect($cipher)->toBeString();
    expect($cipher)->not->toContain('plaintext-secret-value');
    expect(strlen((string) $cipher))->toBeGreaterThan(20);
});

it('round-trips saveInboxRefreshToken + loadInbox', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $expires = new DateTimeImmutable('2026-05-17T08:00:00+00:00');
    $repo->saveInboxRefreshToken(
        inboxId: 42,
        provider: 'gmail',
        email: 'user@example.com',
        refreshToken: 'rt-secret',
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        expiresAt: $expires,
    );

    $loaded = $repo->loadInbox(42);
    expect($loaded)->toBeInstanceOf(InboxCredentials::class);
    expect($loaded->inboxId)->toBe(42);
    expect($loaded->provider)->toBe('gmail');
    expect($loaded->refreshToken)->toBe('rt-secret');
    expect($loaded->scope)->toBe('https://www.googleapis.com/auth/gmail.readonly');
    expect($loaded->expiresAt?->format(DateTimeInterface::ATOM))
        ->toBe('2026-05-17T08:00:00+00:00');
    expect($loaded->accessToken)->toBeNull();
});

it('loadInbox returns null for an unknown inbox id', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    expect($repo->loadInbox(404))->toBeNull();
});

it('saveInboxRefreshToken updates an existing inbox entry in place', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveInboxRefreshToken(7, 'gmail', 'a@example.com', 'rt-1', 'scope-1', null);
    $repo->saveInboxRefreshToken(7, 'gmail', 'b@example.com', 'rt-2', 'scope-2', null);

    $loaded = $repo->loadInbox(7);
    expect($loaded?->refreshToken)->toBe('rt-2');
    expect($loaded?->scope)->toBe('scope-2');
});

it('persists inbox tokens encrypted — a raw read of tokens_blob never sees the token', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveInboxRefreshToken(15, 'gmail', 'x@example.com', 'super-secret-rt', 'scope', null);

    $cipher = $this->db->connection()
        ->table('oauth_secrets')
        ->where('user_id', $this->user->id)
        ->where('provider', 'gmail')
        ->value('tokens_blob');

    expect($cipher)->toBeString();
    expect($cipher)->not->toContain('super-secret-rt');
    expect($cipher)->not->toContain('refresh_token');
});

it('rotateRefreshToken updates the persisted token + cached short-lived token', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveInboxRefreshToken(7, 'microsoft', 'm@example.com', 'old-rt', 'Mail.Read', null);
    $newExpires = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
    $repo->rotateRefreshToken(7, 'new-rt', 'cached-at', $newExpires);

    $loaded = $repo->loadInbox(7);
    expect($loaded?->refreshToken)->toBe('new-rt');
    expect($loaded?->accessToken)->toBe('cached-at');
    expect($loaded?->expiresAt?->format(DateTimeInterface::ATOM))
        ->toBe('2026-06-01T00:00:00+00:00');
});

it('rotateRefreshToken clears the cached access token when none is supplied', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveInboxRefreshToken(8, 'gmail', 'g@example.com', 'rt', 'scope', null);
    $repo->rotateRefreshToken(8, 'rt-2', 'access-cached', null);
    expect($repo->loadInbox(8)?->accessToken)->toBe('access-cached');

    $repo->rotateRefreshToken(8, 'rt-3', null, null);
    expect($repo->loadInbox(8)?->accessToken)->toBeNull();
});

it('rotateRefreshToken on a missing inbox raises RuntimeException', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    expect(fn () => $repo->rotateRefreshToken(404, 'rt', null, null))
        ->toThrow(RuntimeException::class);
});

it('removeInbox drops the entry', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveInboxRefreshToken(99, 'gmail', 'x@example.com', 'rt', 'scope', null);
    expect($repo->loadInbox(99))->not->toBeNull();

    $repo->removeInbox(99);
    expect($repo->loadInbox(99))->toBeNull();
});

it('removeInbox leaves other inboxes of the same provider intact', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveInboxRefreshToken(1, 'gmail', 'one@example.com', 'rt-1', 'scope', null);
    $repo->saveInboxRefreshToken(2, 'gmail', 'two@example.com', 'rt-2', 'scope', null);

    $repo->removeInbox(1);
    expect($repo->loadInbox(1))->toBeNull();
    expect($repo->loadInbox(2)?->refreshToken)->toBe('rt-2');
});

it('saveProviderClient rejects unknown providers', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    expect(fn () => $repo->saveProviderClient('yahoo', 'id', 'sec', 'http://127.0.0.1/cb'))
        ->toThrow(InvalidArgumentException::class);
});
