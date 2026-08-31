<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\SyncPorts;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-peer-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

// The real RelayConfig rather than a double: it is final, and the host has to
// survive the same on-disk round-trip the device performs.
function peerLanAddressWithEndpoint(?string $endpoint): PeerLanAddress
{
    if ($endpoint !== null) {
        /** @var RelayConfig $config */
        $config = app(RelayConfig::class);
        mkdir(dirname(UserDataPathService::appPath('sync/relay.json')), 0700, true);
        $config->setEndpointUrl($endpoint);
    }

    /** @var PeerLanAddress $address */
    $address = app(PeerLanAddress::class);

    return $address;
}

function rememberPeerAt(int $userId, ?string $host, ?int $port): string
{
    $deviceId = 'desktop-peer-'.bin2hex(random_bytes(4));

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Study desktop',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => 'alpha bravo charlie',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:01:00Z',
        'last_seen_at' => '2026-08-01T10:01:00Z',
        'last_lan_host' => $host,
        'last_lan_port' => $port,
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:01:00Z',
    ]);

    return $deviceId;
}

// On a paired, fully synced Galaxy S23 the registry held 192.168.178.119:51337
// and this class answered with the host out of a relay URL — null, on a
// LAN-only pairing — so the manual sync opened no TCP connection at all.

it('answers with the address the phone last reached the desktop at', function (): void {
    rememberPeerAt(7, '192.168.178.119', 51337);

    expect(peerLanAddressWithEndpoint(null)->recall(7))
        ->toBe(['host' => '192.168.178.119', 'port' => 51337]);
});

it('prefers the reached address over the relay endpoint that merely names the machine', function (): void {
    rememberPeerAt(7, '192.168.178.119', 51337);

    expect(peerLanAddressWithEndpoint('https://desk.local:8443/ws')->recall(7))
        ->toBe(['host' => '192.168.178.119', 'port' => 51337]);
});

// The fallback earns its place on iOS, where LAN discovery does not work at
// all, and immediately after a QR pairing that has reached nothing yet.
it('falls back to the relay endpoint host when nothing was ever reached', function (): void {
    rememberPeerAt(7, null, null);

    expect(peerLanAddressWithEndpoint('https://desk.local:8443/ws')->recall(7))
        ->toBe(['host' => 'desk.local', 'port' => SyncPorts::DEFAULT_PORT]);
});

// No endpoint and nothing reached is the out-of-box state, not a failure: the
// LAN leg is simply not attempted rather than dialled against a null host.
it('has no address before a peer is reached or an endpoint is learned', function (): void {
    expect(peerLanAddressWithEndpoint(null)->recall(7))->toBeNull();
});

it('has no address when the stored endpoint carries no host', function (): void {
    expect(peerLanAddressWithEndpoint('not-a-url')->recall(7))->toBeNull();
});

// The desktop honouring SYNC_PORT while the phone dials a compiled-in 51337
// leaves the LAN leg calling a closed port, which presents as the relay-only
// "0 of 0 records" this class was written to fix.
it('dials the configured sync port rather than the compiled-in default', function (): void {
    config(['sync.port' => 51999]);

    expect(peerLanAddressWithEndpoint('https://desk.local:8443/ws')->recall(7))
        ->toBe(['host' => 'desk.local', 'port' => 51999]);
});

it('drops a remembered address on request, so the next look-up browses again', function (): void {
    rememberPeerAt(7, '192.168.178.119', 51337);

    $address = peerLanAddressWithEndpoint(null);
    $address->forget(7);

    expect($address->recall(7))->toBeNull();
});

// An unconfirmed or self row is not a peer to dial: otherwise a phone would
// look up its own address and call the result the desktop.
it('ignores its own registry row when choosing whose address to answer with', function (): void {
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => 7,
        'device_id' => 'this-phone',
        'name' => 'This phone',
        'ed25519_public_key_hex' => str_repeat('ef', 32),
        'x25519_public_key_hex' => str_repeat('01', 32),
        'safety_number_words' => 'delta echo foxtrot',
        'is_self' => 1,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:01:00Z',
        'last_seen_at' => '2026-08-01T10:01:00Z',
        'last_lan_host' => '192.168.178.70',
        'last_lan_port' => 51337,
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:01:00Z',
    ]);

    expect(peerLanAddressWithEndpoint(null)->recall(7))->toBeNull();
});
