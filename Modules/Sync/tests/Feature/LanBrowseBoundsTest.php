<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Pairing\LanPairingFrameCourier;
use Modules\Sync\Internal\Pairing\LanPairingFramePuller;
use Modules\Sync\Internal\Pairing\LanPairingOfferFetcher;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;

uses(RefreshDatabase::class);

// The three LAN roads share one browse-and-dial. They do NOT share one bound,
// and the difference is deliberate: the offer fetcher has no device id to aim
// at, so any peer on the network might be the one holding the typed code, and
// the pairing-handshake page records eight for exactly that reason. Every other
// road either names the device it wants or runs on a three-second poll, and
// stops at four. This pins the difference, because a later merge that flattens
// it would either halve the reach of a typed code or double the blocking work
// inside a poll — and neither has a test that would notice.

/**
 * @param  list<DiscoveredPeer>  $peers
 */
function browseBoundsDiscovery(array $peers): PeerDiscovery
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

/**
 * @return list<DiscoveredPeer>
 */
function browseBoundsPeers(int $count, string $deviceId = 'desktop-lan'): array
{
    $peers = [];

    for ($i = 0; $i < $count; $i++) {
        $peers[] = new DiscoveredPeer($deviceId, '192.0.2.'.(10 + $i), 51337, DiscoveryMode::Mdns);
    }

    return $peers;
}

function browseBoundsIdentity(): DeviceIdentityDto
{
    $keypair = sodium_crypto_sign_keypair();

    return new DeviceIdentityDto(
        version: 1,
        deviceId: '11111111-2222-4333-8444-666666666666',
        userId: 1,
        ed25519SecretKeyHex: sodium_bin2hex(sodium_crypto_sign_secretkey($keypair)),
        ed25519PublicKeyHex: sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
        x25519SecretKeyHex: bin2hex(random_bytes(32)),
        x25519PublicKeyHex: bin2hex(random_bytes(32)),
        createdAt: '2026-06-15T10:00:00Z',
    );
}

it('asks eight peers for an offer, because any of them might hold the typed code', function (): void {
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    $fetcher = new LanPairingOfferFetcher(
        new LanPeerBrowser(app(HttpFactory::class), browseBoundsDiscovery(browseBoundsPeers(20))),
        new WordCodeEncoder,
    );

    $fetcher->fetchForWordCode((new WordCodeEncoder)->encode(bin2hex(random_bytes(16))));

    Http::assertSentCount(8);
});

it('stops at four peers when it is delivering to a device it can name', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    $courier = new LanPairingFrameCourier(
        new LanPeerBrowser(app(HttpFactory::class), browseBoundsDiscovery(browseBoundsPeers(20, 'peer-device'))),
    );

    expect($courier->deliver('peer-device', ['type' => 'accept']))->toBeFalse();

    Http::assertSentCount(4);
});

// The bound counts the peers this delivery could actually use. Counting every
// connectable answer instead would let a crowded network spend the whole
// allowance on devices the frame was never addressed to, and the one peer that
// was addressed would never be reached.
it('spends the delivery bound only on peers advertising the named device', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    $addressed = new DiscoveredPeer('peer-device', '192.0.2.99', 51337, DiscoveryMode::Mdns);
    $peers = [...browseBoundsPeers(6, 'someone-else'), $addressed];

    $courier = new LanPairingFrameCourier(
        new LanPeerBrowser(app(HttpFactory::class), browseBoundsDiscovery($peers)),
    );

    expect($courier->deliver('peer-device', ['type' => 'accept']))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => str_starts_with($request->url(), 'http://192.0.2.99:51337/'));
});

it('asks four peers for waiting frames, because the pull runs on every poll', function (): void {
    Http::fake(['*' => Http::response(['frames' => []])]);

    $puller = new LanPairingFramePuller(
        new LanPeerBrowser(app(HttpFactory::class), browseBoundsDiscovery(browseBoundsPeers(20))),
        app(PairingFrameApplier::class),
        app(DeviceKeySigner::class),
    );

    $puller->pullAndApply(1, browseBoundsIdentity());

    Http::assertSentCount(4);
});
