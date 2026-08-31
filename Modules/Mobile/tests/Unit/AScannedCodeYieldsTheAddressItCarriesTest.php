<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Internal\Pairing\QrScanBridge;

uses(RefreshDatabase::class);

// Read verbatim like every other scanned field: the address decides which
// machine is asked, never which one is trusted, and the safety number still
// settles that. It matters on a phone because iOS grants no multicast
// entitlement, so a scan is the only way this device learns where to send.

function scannedAddressPayload(string $suffix = ''): string
{
    return 'beatrax://pair?v=1&token=deadbeef&ed='.str_repeat('a', 64)
        .'&kx='.str_repeat('b', 64)
        .'&device=desktop-x'.$suffix;
}

it('reads the host and port the code was minted with', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $identity = $bridge->extractIdentity(scannedAddressPayload('&host=192.168.178.119&port=51337'));

    expect($identity)->not->toBeNull()
        ->and($identity['lanHost'] ?? null)->toBe('192.168.178.119')
        ->and($identity['lanPort'] ?? null)->toBe(51337);
});

it('carries no address at all when the code named none', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    $identity = $bridge->extractIdentity(scannedAddressPayload());

    // Absent rather than null, so the seeded row keeps whatever the other
    // roads found instead of being overwritten with nothing.
    expect($identity)->not->toBeNull()
        ->and(array_key_exists('lanHost', $identity))->toBeFalse()
        ->and(array_key_exists('lanPort', $identity))->toBeFalse();
});

it('refuses half an address rather than dialling a port on nothing', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    expect(array_key_exists('lanPort', (array) $bridge->extractIdentity(scannedAddressPayload('&port=51337'))))->toBeFalse()
        ->and(array_key_exists('lanHost', (array) $bridge->extractIdentity(scannedAddressPayload('&host=192.168.178.119'))))->toBeFalse();
});

it('refuses a port outside the range a listener can hold', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    foreach (['0', '65536', '-1', 'abc', ''] as $port) {
        $identity = (array) $bridge->extractIdentity(scannedAddressPayload('&host=192.168.178.119&port='.$port));

        expect(array_key_exists('lanPort', $identity))->toBeFalse();
    }
});

it('still reads the rest of the code when the address is unusable', function (): void {
    /** @var QrScanBridge $bridge */
    $bridge = app(QrScanBridge::class);

    // A bad address must not cost the reader the whole scan: the other roads
    // are still there and the token is what the ceremony turns on.
    $identity = $bridge->extractIdentity(scannedAddressPayload('&host=&port=99999'));

    expect($identity)->not->toBeNull()
        ->and($identity['token'])->toBe('deadbeef')
        ->and($identity['deviceId'])->toBe('desktop-x');
});
