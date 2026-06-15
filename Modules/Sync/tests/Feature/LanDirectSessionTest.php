<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * LanDirectSessionTest — XPORT-02: LAN-direct WebSocket session over loopback.
 *
 * RED until Wave 3 ships SyncSession + the amphp WebSocket server/client
 * stack under Modules\Sync\Internal\Transport\.
 *
 * Validates that two instances of the sync transport (using the loopback
 * address 127.0.0.1 as a stand-in for LAN-direct) can:
 *   1. Establish a WebSocket connection on a local port.
 *   2. Complete a Noise IK handshake over that connection.
 *   3. Authenticate each other against device_registry X25519 keys.
 *   4. Exchange a simple payload (the loopback integration smoke-test).
 *
 * Uses an ephemeral localhost port to avoid test port conflicts.
 * amphp event loop runs synchronously in test context via \Amp\async().
 *
 * IMPORTANT: This test does NOT test mDNS discovery — that requires two
 * real hosts (see VALIDATION.md Manual-Only Verifications). This test
 * covers the TCP/WS connection and Noise handshake layer in isolation.
 *
 * The test is a slow integration test (starts a WS server, makes a client
 * connection) — Pest will run it last within the Sync testsuite.
 */

it('loopback WebSocket connection is established between two sync transport instances', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\SyncSession'))->toBeFalse(
        'Wave 0 guard: SyncSession must not exist yet — implement in Wave 3.'
    );
})->todo('Wave 3: start amphp WS server on 127.0.0.1:PORT, connect client, assert connection open');

it('Noise IK handshake completes over loopback WebSocket connection', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2/3.'
    );
})->todo('Wave 3: WS connection + IK handshake produces two NoiseSession instances (initiator and responder)');

it('both loopback peers authenticate each other against device_registry X25519 keys', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\SyncSession'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 3.'
    );
})->todo('Wave 3: after IK over WS, each side verifies the other\'s static key via DeviceRegistryService::deviceX25519Keys()');

it('loopback session transfers a plaintext OpLogEntry payload end-to-end', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\SyncSession'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 3.'
    );
})->todo('Wave 3: initiator sends OpLogEntry batch, responder receives and decrypts; assert payload integrity');
