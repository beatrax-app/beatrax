<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\RelayEndpointHost;

/*
 * Where the phone dials the desktop directly.
 *
 * The puller only attempts the LAN leg when it is given a host AND a port, and
 * every caller passed neither — so the leg never ran, the relay fallback
 * drained the mailbox without applying rows, and the device sat at "0 of 0
 * records". The address comes from the relay endpoint the QR carried, which
 * names the desktop that issued it; that is the only address this device is
 * ever told.
 *
 * The real RelayConfig is used rather than a double: it is final, and the
 * host has to survive the same on-disk round-trip the device performs.
 */

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-peer-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

function peerLanAddress(?string $endpoint): PeerLanAddress
{
    $config = app(RelayConfig::class);

    if ($endpoint !== null) {
        mkdir(dirname(UserDataPathService::appPath('sync/relay.json')), 0700, true);
        $config->setEndpointUrl($endpoint);
    }

    return new PeerLanAddress(new RelayEndpointHost($config));
}

it('reads the desktop host out of the relay endpoint', function (): void {
    expect(peerLanAddress('https://desk.local:8443/ws')->host())->toBe('desk.local');
});

// No endpoint is the out-of-box state, not a failure: the LAN leg is simply
// not attempted rather than dialled against a null host.
it('has no host before an endpoint is learned', function (): void {
    expect(peerLanAddress(null)->host())->toBeNull();
});

it('has no host when the stored endpoint carries none', function (): void {
    expect(peerLanAddress('not-a-url')->host())->toBeNull();
});

// The port is the one `sync:serve` listens on, and is deliberately NOT the
// relay endpoint's port — the relay is a different service on a different box.
it('dials the sync port rather than the relay endpoint port', function (): void {
    $address = peerLanAddress('https://desk.local:8443/ws');

    expect($address->port())->toBe(PeerLanAddress::SYNC_PORT)
        ->and($address->port())->toBe(51337);
});
