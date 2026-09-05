<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Internal\Pairing\QrScanBridge;

uses(RefreshDatabase::class);

// The relay fields are read verbatim, exactly like the token, device and key
// fields, so no bespoke trust decision happens here. The bridge is resolved through
// the container only because its constructor needs the full DI graph.

it('extracts relayEndpoint + relayPin when the QR carries both', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $payload = 'beatrax://pair?v=1&token=deadbeef&ed='.str_repeat('a', 64)
        .'&kx='.str_repeat('b', 64)
        .'&device=desktop-x'
        .'&relay='.rawurlencode('https://relay.example.com')
        .'&rpin='.rawurlencode('sha256//pinned');

    $identity = $bridge->extractIdentity($payload);

    expect($identity)->not->toBeNull();
    expect($identity['relayEndpoint'])->toBe('https://relay.example.com');
    expect($identity['relayPin'])->toBe('sha256//pinned');
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
    expect($identity['relayPin'])->toBeNull();
});

it('reads no relay credential out of a QR that still carries one', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    // A code minted by a build that still put a relay-wide bearer in `rtok`.
    // The scan must yield endpoint and pin and nothing else — that bearer is
    // the credential a past peer drained an unclaimed mailbox with.
    $payload = 'beatrax://pair?v=1&token=deadbeef&ed='.str_repeat('a', 64)
        .'&kx='.str_repeat('b', 64)
        .'&device=desktop-x'
        .'&relay='.rawurlencode('https://relay.example.com')
        .'&rtok='.rawurlencode('shared-secret')
        .'&rpin='.rawurlencode('sha256//pinned');

    $identity = $bridge->extractIdentity($payload);

    expect($identity)->not->toBeNull()
        ->and($identity)->toHaveKeys(['relayEndpoint', 'relayPin'])
        ->and($identity)->not->toHaveKey('relayAuthToken')
        ->and($identity['relayPin'])->toBe('sha256//pinned');
});
