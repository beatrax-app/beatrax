<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

// The shape is deterministic on purpose — four keys, no timestamp — so the
// release smoke probe can assert liveness and the built version with one jq
// filter. The route sits outside the `web` group because EnsureDatabaseReady
// would otherwise redirect a pre-migration probe to desktop.setup.

it('responds with HTTP 200 and JSON content type', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('returns exactly the four contract keys with no extras', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertJsonStructure([
        'status',
        'app_version',
        'php_version',
        'sqlite_version',
    ]);

    /** @var array<string, mixed> $body */
    $body = $response->json();
    expect(array_keys($body))->toEqualCanonicalizing([
        'status',
        'app_version',
        'php_version',
        'sqlite_version',
    ]);
    expect($body)->not->toHaveKey('timestamp');
});

it('reports status "ok"', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertExactJson([
        'status' => 'ok',
        'app_version' => (string) (getenv('NATIVEPHP_APP_VERSION') ?: 'dev'),
        'php_version' => PHP_VERSION,
        'sqlite_version' => $this->app->make(DatabaseManager::class)
            ->connection()
            ->scalar('SELECT sqlite_version()'),
    ]);
});

it('reports the NATIVEPHP_APP_VERSION env var when set', function (): void {
    putenv('NATIVEPHP_APP_VERSION=0.1.0-rc.1');
    try {
        $response = $this->get('/health');

        $response->assertOk();
        expect($response->json('app_version'))->toBe('0.1.0-rc.1');
    } finally {
        putenv('NATIVEPHP_APP_VERSION');
    }
});

it('reports app_version "dev" when NATIVEPHP_APP_VERSION is unset', function (): void {
    putenv('NATIVEPHP_APP_VERSION');

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('app_version'))->toBe('dev');
});

it('reports the PHP_VERSION constant verbatim', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('php_version'))->toBe(PHP_VERSION);
});

it('reports the SQLite library version reported by the default connection', function (): void {
    $expected = $this->app->make(DatabaseManager::class)
        ->connection()
        ->scalar('SELECT sqlite_version()');

    $response = $this->get('/health');

    $response->assertOk();
    $reported = $response->json('sqlite_version');
    expect($reported)->toBeString();
    expect($reported)->toBe((string) $expected);
    expect($reported)->toMatch('/^\d+\.\d+(?:\.\d+)?/');
});

it('is reachable without any authenticated session and does not redirect to login', function (): void {
    // No actingAs(): the smoke probe runs before any user exists, so the 302 to
    // /login an auth-gated route answers with would fail it.
    $response = $this->get('/health');

    $response->assertOk();
    expect($response->status())->not->toBe(302);
});
