<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Pairing\LanPairingFramePuller;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;

uses(RefreshDatabase::class);

// The collecting half of the return leg. It asks an unauthenticated peer for
// frames addressed to it, so it has to say who it is in a way a stranger on the
// same wifi cannot copy — and the listener checks that against the key its own
// pairing row bound.

/**
 * @param  list<DiscoveredPeer>  $peers
 */
function framePuller(array $peers): LanPairingFramePuller
{
    $discovery = new class($peers) implements PeerDiscovery
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

    return new LanPairingFramePuller(
        new LanPeerBrowser(app(HttpFactory::class), $discovery),
        app(PairingFrameApplier::class),
        app(DeviceKeySigner::class),
    );
}

function pullerIdentity(): DeviceIdentityDto
{
    $keypair = sodium_crypto_sign_keypair();

    return new DeviceIdentityDto(
        version: 1,
        deviceId: '11111111-2222-4333-8444-555555555555',
        userId: 1,
        ed25519SecretKeyHex: sodium_bin2hex(sodium_crypto_sign_secretkey($keypair)),
        ed25519PublicKeyHex: sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
        x25519SecretKeyHex: bin2hex(random_bytes(32)),
        x25519PublicKeyHex: bin2hex(random_bytes(32)),
        createdAt: '2026-06-15T10:00:00Z',
    );
}

it('signs a proof the listener can check against the key it bound', function (): void {
    Http::fake(['*' => Http::response(['frames' => []])]);

    $identity = pullerIdentity();

    framePuller([new DiscoveredPeer('desktop', '192.0.2.44', 51337, DiscoveryMode::Mdns)])
        ->pullAndApply(1, $identity);

    Http::assertSent(static function ($request) use ($identity): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['device'] ?? null) === $identity->deviceId
            && is_string($query['proof'] ?? null)
            && app(DeviceKeySigner::class)->verify(
                PairingFrame::pullProofMessage($identity->deviceId),
                $query['proof'],
                sodium_hex2bin($identity->ed25519PublicKeyHex),
            );
    });
});

it('asks nothing at all when discovery found no peer', function (): void {
    Http::fake();

    expect(framePuller([])->pullAndApply(1, pullerIdentity()))->toBe(0);

    Http::assertNothingSent();
});

// A peer that answered a browse without an address was never dialable, so it
// costs neither a request nor a proof.
it('skips a peer that announced itself without an address', function (): void {
    Http::fake();

    framePuller([new DiscoveredPeer('desktop', '', 0, DiscoveryMode::Mdns)])->pullAndApply(1, pullerIdentity());

    Http::assertNothingSent();
});
