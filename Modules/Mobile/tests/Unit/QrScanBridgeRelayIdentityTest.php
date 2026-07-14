<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Internal\Pairing\QrScanBridge;

uses(RefreshDatabase::class);

/*
 * QrScanBridgeRelayIdentityTest — Phase 15 HIGH-01 (Task 1), case 10.
 *
 * extractIdentity() now ALSO reads the QR's optional `relay`/`rtok` query
 * params (QrPayloadBuilder::buildUri()) so a fresh phone can auto-configure
 * its own RelayConfig before the cross-device confirm handshake needs a
 * transport. This is still no bespoke trust decision — the fields are read
 * verbatim, exactly like the existing token/device/ed/kx fields.
 *
 * QrScanBridge is resolved via the container (its PairingGateway dependency
 * needs the full DI graph) but extractIdentity() itself never touches
 * PairingGateway — it is a pure envelope-unwrap.
 */

it('extracts relayEndpoint + relayAuthToken when the QR carries both', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $payload = 'beatrax://pair?v=1&token=deadbeef&ed='.str_repeat('a', 64)
        .'&kx='.str_repeat('b', 64)
        .'&device=desktop-x'
        .'&relay='.rawurlencode('https://relay.example.com')
        .'&rtok='.rawurlencode('shared-secret');

    $identity = $bridge->extractIdentity($payload);

    expect($identity)->not->toBeNull();
    expect($identity['relayEndpoint'])->toBe('https://relay.example.com');
    expect($identity['relayAuthToken'])->toBe('shared-secret');
});

it('yields a null relayEndpoint (never a failure) when the QR carries no relay param — no dead end', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $payload = 'beatrax://pair?v=1&token=deadbeef&ed='.str_repeat('a', 64)
        .'&kx='.str_repeat('b', 64)
        .'&device=desktop-x';

    $identity = $bridge->extractIdentity($payload);

    expect($identity)->not->toBeNull();
    expect($identity['relayEndpoint'])->toBeNull();
    expect($identity['relayAuthToken'])->toBeNull();
});

it('ignores rtok when relay is absent — never a bare relayAuthToken with no endpoint', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $payload = 'beatrax://pair?v=1&token=deadbeef&ed='.str_repeat('a', 64)
        .'&kx='.str_repeat('b', 64)
        .'&device=desktop-x'
        .'&rtok='.rawurlencode('shared-secret');

    $identity = $bridge->extractIdentity($payload);

    expect($identity)->not->toBeNull();
    expect($identity['relayEndpoint'])->toBeNull();
    expect($identity['relayAuthToken'])->toBeNull();
});
