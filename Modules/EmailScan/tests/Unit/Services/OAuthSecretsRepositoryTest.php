<?php

declare(strict_types=1);

use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

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

it('removeInbox drops the entry', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveInboxRefreshToken(99, 'gmail', 'x@example.com', 'rt', 'scope', null);
    expect($repo->loadInbox(99))->not->toBeNull();

    $repo->removeInbox(99);
    expect($repo->loadInbox(99))->toBeNull();
});

it('persists the JSON file with chmod 0600 and dir 0700', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('chmod semantics differ on Windows.');
    }
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveProviderClient('gmail', 'id', 'sec', 'http://127.0.0.1/cb');
    expect(is_file($this->path))->toBeTrue();

    $fileMode = fileperms($this->path) & 0777;
    expect(decoct($fileMode))->toBe('600');

    $dirMode = fileperms(dirname($this->path)) & 0777;
    expect(decoct($dirMode))->toBe('700');
});

it('rotateRefreshToken on a missing inbox raises RuntimeException', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    expect(fn () => $repo->rotateRefreshToken(404, 'rt', null, null))
        ->toThrow(RuntimeException::class);
});

it('saveProviderClient rejects unknown providers', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    expect(fn () => $repo->saveProviderClient('yahoo', 'id', 'sec', 'http://127.0.0.1/cb'))
        ->toThrow(InvalidArgumentException::class);
});
