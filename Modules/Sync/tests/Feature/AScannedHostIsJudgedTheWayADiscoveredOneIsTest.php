<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Pairing\LanPairingFramePuller;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\ScannedPeerAddress;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Support\LanOnlyHost;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;

uses(RefreshDatabase::class);

// An mDNS address is the host a datagram physically arrived from, so it is on
// this network by construction. A scanned `host=` is a string whoever produced
// the QR chose freely, and it reached the same dial with nothing asked of it.

/**
 * @param  list<DiscoveredPeer>  $peers
 */
function scannedHostPuller(array $peers = []): LanPairingFramePuller
{
    return new LanPairingFramePuller(
        new LanPeerBrowser(app(HttpFactory::class), scannedHostDiscovery($peers)),
        app(PairingFrameApplier::class),
        app(DeviceKeySigner::class),
        app(ScannedPeerAddress::class),
    );
}

/**
 * @param  list<DiscoveredPeer>  $peers
 */
function scannedHostDiscovery(array $peers = []): PeerDiscovery
{
    return new class($peers) implements PeerDiscovery
    {
        /**
         * @param  list<DiscoveredPeer>  $peers
         */
        public function __construct(private readonly array $peers) {}

        public function reach(): LanDiscoveryReach
        {
            return LanDiscoveryReach::Available;
        }

        /**
         * @return list<DiscoveredPeer>
         */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return $this->peers;
        }
    };
}

function scannedHostIdentity(): DeviceIdentityDto
{
    $keypair = sodium_crypto_sign_keypair();

    return new DeviceIdentityDto(
        version: 1,
        deviceId: '77777777-2222-4333-8444-555555555555',
        userId: 1,
        ed25519SecretKeyHex: sodium_bin2hex(sodium_crypto_sign_secretkey($keypair)),
        ed25519PublicKeyHex: sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
        x25519SecretKeyHex: bin2hex(random_bytes(32)),
        x25519PublicKeyHex: bin2hex(random_bytes(32)),
        createdAt: '2026-06-15T10:00:00Z',
    );
}

function scannedHostRow(string $responderDeviceId, string $host): void
{
    app(DatabaseManager::class)->connection()->table('pairing_tokens')->insert([
        'user_id' => 1,
        'token_hash' => hash('sha256', $responderDeviceId.$host),
        'initiator_device_id' => 'desktop',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $responderDeviceId,
        'state' => 'awaiting_confirm',
        'expires_at' => '2099-01-01T00:00:00Z',
        'initiator_lan_host' => $host,
        'initiator_lan_port' => 51337,
        'created_at' => '2026-06-15T10:00:00Z',
    ]);
}

it('dials a peer the browse found, whatever the scanned column holds', function (): void {
    // The control. It would still pass with the rule refusing every host there
    // is, so the silences below are the rule speaking rather than a harness
    // that was never going to send anything.
    Http::fake(['*' => Http::response(['frames' => []])]);

    scannedHostPuller([new DiscoveredPeer('desktop', '192.168.0.10', 51337, DiscoveryMode::Mdns)])
        ->pullAndApply(1, scannedHostIdentity());

    Http::assertSent(static fn ($request): bool => str_starts_with($request->url(), 'http://192.168.0.10:51337/'));
});

it('dials the scanned address when it names a machine on this network', function (): void {
    Http::fake(['*' => Http::response(['frames' => []])]);

    $identity = scannedHostIdentity();
    scannedHostRow($identity->deviceId, '192.168.0.77');

    scannedHostPuller()->pullAndApply(1, $identity);

    Http::assertSent(static fn ($request): bool => str_starts_with($request->url(), 'http://192.168.0.77:51337/'));
});

it('asks nothing of a scanned address that is routable off this network', function (): void {
    Http::fake();

    $identity = scannedHostIdentity();
    scannedHostRow($identity->deviceId, '93.184.216.34');

    scannedHostPuller()->pullAndApply(1, $identity);

    Http::assertNothingSent();
});

it('asks nothing of a scanned name, because DNS answers to whoever runs it', function (): void {
    Http::fake();

    $identity = scannedHostIdentity();
    scannedHostRow($identity->deviceId, 'pairing.example.com');

    scannedHostPuller()->pullAndApply(1, $identity);

    Http::assertNothingSent();
});

it('asks nothing of the link-local metadata address', function (): void {
    Http::fake();

    $identity = scannedHostIdentity();
    scannedHostRow($identity->deviceId, '169.254.169.254');

    scannedHostPuller()->pullAndApply(1, $identity);

    Http::assertNothingSent();
});

it('refuses to follow a redirect the peer that answered chose', function (): void {
    // Whoever answers the probe writes the Location, and the request carries a
    // pull proof and a token hash in its query string.
    $browser = new LanPeerBrowser(app(HttpFactory::class), scannedHostDiscovery());

    expect($browser->peerRequest()->getOptions()['allow_redirects'] ?? null)->toBeFalse();
});

it('judges a host the way the relay endpoint out of the same QR is judged', function (string $host, bool $admitted): void {
    expect(LanOnlyHost::admits($host))->toBe($admitted);
})->with([
    'a home LAN address' => ['192.168.1.20', true],
    'the 10/8 block' => ['10.4.4.4', true],
    'the 172.16/12 block' => ['172.20.0.9', true],
    'loopback' => ['127.0.0.1', true],
    'a public address' => ['93.184.216.34', false],
    'the cloud metadata endpoint' => ['169.254.169.254', false],
    'a name that resolves to anywhere' => ['pairing.example.com', false],
    'the name of this machine' => ['localhost', false],
    'nothing at all' => ['', false],
    'an address with a port glued on' => ['192.168.1.20:51337', false],
]);
