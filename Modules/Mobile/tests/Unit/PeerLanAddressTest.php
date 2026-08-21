<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\RelayEndpointHost;
use Modules\Sync\Public\Services\SyncPorts;

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-peer-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

// The real RelayConfig rather than a double: it is final, and the host has to
// survive the same on-disk round-trip the device performs.
function peerLanAddress(?string $endpoint): PeerLanAddress
{
    $config = app(RelayConfig::class);

    if ($endpoint !== null) {
        mkdir(dirname(UserDataPathService::appPath('sync/relay.json')), 0700, true);
        $config->setEndpointUrl($endpoint);
    }

    return new PeerLanAddress(new RelayEndpointHost($config), app(SyncPorts::class));
}

// The puller only attempts the LAN leg when given a host and a port, and every
// caller passed neither, so the relay fallback drained the mailbox without applying
// rows and the device sat at "0 of 0 records". The address comes from the relay
// endpoint the QR carried, which is the only one this device is ever told.

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

    expect($address->port())->toBe(SyncPorts::DEFAULT_PORT)
        ->and($address->port())->toBe(51337);
});

// The desktop honouring SYNC_PORT while the phone dials a compiled-in 51337
// leaves the LAN leg calling a closed port, which presents as the relay-only
// "0 of 0 records" this class was written to fix.
it('dials the configured sync port rather than the compiled-in default', function (): void {
    config(['sync.port' => 51999]);

    expect(peerLanAddress('https://desk.local:8443/ws')->port())->toBe(51999);
});
