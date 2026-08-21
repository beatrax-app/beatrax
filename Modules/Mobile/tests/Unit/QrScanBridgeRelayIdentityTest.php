<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Internal\Pairing\QrScanBridge;

uses(RefreshDatabase::class);

// The relay fields are read verbatim, exactly like the token, device and key
// fields, so no bespoke trust decision happens here. The bridge is resolved through
// the container only because its constructor needs the full DI graph.

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
