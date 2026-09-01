<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Pairing\LanPairingFramePuller;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\ScannedPeerAddress;
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
        app(ScannedPeerAddress::class),
    );
}

// The row a scan seeds on the responder, carrying the address the QR named.
// Only `initiator_lan_host/port` matter here; the rest is what the column
// definitions require of any row at all.
function pullerScannedRow(string $responderDeviceId, ?string $host, ?int $port, string $state = 'awaiting_confirm'): void
{
    app(DatabaseManager::class)->connection()->table('pairing_tokens')->insert([
        'user_id' => 1,
        'token_hash' => hash('sha256', $responderDeviceId.$state.(string) $port),
        'initiator_device_id' => 'desktop',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $responderDeviceId,
        'state' => $state,
        'expires_at' => '2099-01-01T00:00:00Z',
        'initiator_lan_host' => $host,
        'initiator_lan_port' => $port,
        'created_at' => '2026-06-15T10:00:00Z',
    ]);
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

it('asks nothing at all when discovery found no peer and no code was scanned', function (): void {
    Http::fake();

    expect(framePuller([])->pullAndApply(1, pullerIdentity()))->toBe(0);

    Http::assertNothingSent();
});

// The iPhone case. A browse returns nothing there and cannot be made to, so a
// collect leg that only ever asks browse-discovered peers has no road at all —
// the desktop's confirm stays uncollected and the ceremony finishes on one
// screen only. The scan already recorded where the initiator is.
it('asks the address the scanned code named when the browse finds nothing', function (): void {
    Http::fake(['*' => Http::response(['frames' => []])]);

    $identity = pullerIdentity();
    pullerScannedRow($identity->deviceId, '192.0.2.77', 51337);

    framePuller([])->pullAndApply(1, $identity);

    Http::assertSent(static fn ($request): bool => str_starts_with($request->url(), 'http://192.0.2.77:51337/pair/frames'));
});

it('does not ask the scanned address twice when the browse names it too', function (): void {
    Http::fake(['*' => Http::response(['frames' => []])]);

    $identity = pullerIdentity();
    pullerScannedRow($identity->deviceId, '192.0.2.77', 51337);

    framePuller([new DiscoveredPeer('desktop', '192.0.2.77', 51337, DiscoveryMode::Mdns)])
        ->pullAndApply(1, $identity);

    Http::assertSentCount(1);
});

// A ceremony that already ended names an address nothing is waiting at, and a
// row belonging to another device on this account is not this device's road.
it('ignores a scanned address from a finished ceremony or another device', function (): void {
    Http::fake();

    $identity = pullerIdentity();
    pullerScannedRow($identity->deviceId, '192.0.2.77', 51337, 'confirmed');
    pullerScannedRow('99999999-2222-4333-8444-555555555555', '192.0.2.78', 51337);

    framePuller([])->pullAndApply(1, $identity);

    Http::assertNothingSent();
});

// A peer that answered a browse without an address was never dialable, so it
// costs neither a request nor a proof.
it('skips a peer that announced itself without an address', function (): void {
    Http::fake();

    framePuller([new DiscoveredPeer('desktop', '', 0, DiscoveryMode::Mdns)])->pullAndApply(1, pullerIdentity());

    Http::assertNothingSent();
});
