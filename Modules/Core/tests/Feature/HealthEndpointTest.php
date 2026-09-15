<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Internal\Support\NetworkBoundary;

// The shape is deterministic on purpose — five keys, no timestamp — so the
// release smoke probe can assert liveness and the built version with one jq
// filter. The route sits outside the `web` group because EnsureDatabaseReady
// would otherwise redirect a pre-migration probe to desktop.setup.

// `network_boundary` carries the state word and never the interface list: once
// the boundary is open this body crosses the network, where an inventory of the
// other interfaces served would be a disclosure rather than a diagnosis.

it('responds with HTTP 200 and JSON content type', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('returns exactly the five contract keys with no extras', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertJsonStructure([
        'status',
        'app_version',
        'php_version',
        'sqlite_version',
        'network_boundary',
    ]);

    /** @var array<string, mixed> $body */
    $body = $response->json();
    expect(array_keys($body))->toEqualCanonicalizing([
        'status',
        'app_version',
        'php_version',
        'sqlite_version',
        'network_boundary',
    ]);
    expect($body)->not->toHaveKey('timestamp');
    expect($body)->not->toHaveKey('served_interfaces');
});

it('reports status "ok"', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertExactJson([
        'status' => 'ok',
        'app_version' => (string) (config('nativephp.version') ?: 'dev'),
        'php_version' => PHP_VERSION,
        'sqlite_version' => $this->app->make(DatabaseManager::class)
            ->connection()
            ->scalar('SELECT sqlite_version()'),
        'network_boundary' => 'loopback',
    ]);
});

it('reports the boundary as loopback on an install that recorded no interface', function (): void {
    expect($this->app->make(NetworkBoundary::class)->isWidened())->toBeFalse();

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('network_boundary'))->toBe('loopback');
});

it('reports the boundary as widened once an interface is recorded', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.10']);

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('network_boundary'))->toBe('widened');
});

// A record the gate refused widened nothing, so the surface must not report a
// boundary the app is not actually keeping open.
it('keeps reporting loopback when the only recorded entry was refused', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '0.0.0.0']);

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('network_boundary'))->toBe('loopback');
});

it('never names an interface in the body it hands a caller', function (): void {
    config([NetworkBoundary::CONFIG_KEY => '192.168.1.10']);

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->getContent())->not->toContain('192.168.1.10');
});

it('reports the version the bundle was configured with', function (): void {
    config(['nativephp.version' => '0.1.0-rc.1']);

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('app_version'))->toBe('0.1.0-rc.1');
});

it('reports app_version "dev" when the bundle carries no version', function (): void {
    config(['nativephp.version' => null]);

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('app_version'))->toBe('dev');
});

// The regression this file exists to hold. A packaged bundle runs `artisan
// optimize` at startup; with configuration cached Laravel never runs Dotenv, so
// getenv answers false however well the .env was staged. Reading the process
// environment here shipped `dev` on every release bundle.
it('answers from config even when the process environment disagrees', function (): void {
    config(['nativephp.version' => '2.0.0']);
    putenv('NATIVEPHP_APP_VERSION=this-must-not-win');

    try {
        $response = $this->get('/health');

        $response->assertOk();
        expect($response->json('app_version'))->toBe('2.0.0');
    } finally {
        putenv('NATIVEPHP_APP_VERSION');
    }
});

it('still answers when the process environment carries nothing at all', function (): void {
    config(['nativephp.version' => '2.0.0']);
    putenv('NATIVEPHP_APP_VERSION');

    $response = $this->get('/health');

    $response->assertOk();
    expect($response->json('app_version'))->toBe('2.0.0');
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
