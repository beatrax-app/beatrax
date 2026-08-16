<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

/*
 * Which relay endpoints the transport will talk to in the clear.
 *
 * Plaintext to a public host exposes ciphertext sizes and the routing
 * metadata — who is syncing with whom — so http:// is only ever accepted to a
 * host that cannot leave this network. That is the out-of-box pairing path:
 * the desktop's own relay, reachable only from this LAN.
 *
 * All three questions reduce to classifying the host, which is why they now
 * share one answer. The table below is what that answer has to be; a domain
 * name is never LAN however it resolves today, because it is not ours to
 * assume it resolves the same way tomorrow.
 */

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-relay-class-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

it('treats only unroutable hosts as LAN', function (string $endpoint, bool $isLan): void {
    expect(app(RelayConfig::class)->isLanEndpoint($endpoint))->toBe($isLan);
})->with([
    'loopback name' => ['http://localhost:8443/ws', true],
    'loopback address' => ['http://127.0.0.1:8443/ws', true],
    'private class C' => ['http://192.168.1.10:8443/ws', true],
    'private class A' => ['http://10.0.0.5:8443/ws', true],
    'public address' => ['http://8.8.8.8:8443/ws', false],
    'domain name' => ['http://relay.example/ws', false],
    'https to a LAN address' => ['https://192.168.1.10:8443/ws', true],
    'no host at all' => ['not-a-url', false],
]);

it('accepts https anywhere but plaintext only on this network', function (string $endpoint, bool $accepted): void {
    expect(app(RelayConfig::class)->wouldAcceptEndpoint($endpoint))->toBe($accepted);
})->with([
    'https to a public relay' => ['https://relay.example/ws', true],
    'https to a LAN address' => ['https://192.168.1.10:8443/ws', true],
    'plaintext to loopback' => ['http://localhost:8443/ws', true],
    'plaintext to a private address' => ['http://192.168.1.10:8443/ws', true],
    'plaintext to a public address' => ['http://8.8.8.8:8443/ws', false],
    'plaintext to a domain' => ['http://relay.example/ws', false],
    'not a URL at all' => ['relay.example', false],
]);

// Storing an endpoint the client would later refuse leaves a device holding a
// relay it can never send to, so the two answers have to agree.
it('never accepts an endpoint it would call publicly insecure', function (string $endpoint): void {
    $config = app(RelayConfig::class);
    mkdir(dirname(UserDataPathService::appPath('sync/relay.json')), 0700, true);
    $config->setEndpointUrl($endpoint);

    expect($config->isPubliclyInsecure())->toBe(! $config->wouldAcceptEndpoint($endpoint));
})->with([
    ['https://relay.example/ws'],
    ['http://localhost:8443/ws'],
    ['http://192.168.1.10:8443/ws'],
    ['http://8.8.8.8:8443/ws'],
    ['http://relay.example/ws'],
]);

it('reports a stored plaintext endpoint to a public host as insecure', function (): void {
    $config = app(RelayConfig::class);
    mkdir(dirname(UserDataPathService::appPath('sync/relay.json')), 0700, true);
    $config->setEndpointUrl('http://8.8.8.8:8443/ws');

    expect($config->isInsecure())->toBeTrue()
        ->and($config->isPubliclyInsecure())->toBeTrue();
});

// The desktop's own relay is plaintext by design and must NOT be reported as
// a risk, or the pairing path warns about itself on every launch.
it('does not report the desktop relay on this LAN as insecure', function (): void {
    $config = app(RelayConfig::class);
    mkdir(dirname(UserDataPathService::appPath('sync/relay.json')), 0700, true);
    $config->setEndpointUrl('http://192.168.1.10:8443/ws');

    expect($config->isInsecure())->toBeTrue()
        ->and($config->isPubliclyInsecure())->toBeFalse();
});
