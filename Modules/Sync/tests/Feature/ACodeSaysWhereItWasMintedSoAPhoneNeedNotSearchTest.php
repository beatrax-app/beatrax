<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Pairing\LanBootstrap;
use Modules\Sync\Internal\Pairing\LanPairingFrameCourier;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\ScannedPeerAddress;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Internal\Transport\PairingFrameRequestHandler;
use Modules\Sync\Public\Enums\LanDiscoveryReach;

uses(RefreshDatabase::class);

// A responder finds the initiator by browsing for it, and iOS grants no
// multicast entitlement, so an iPhone browses nothing. The QR was the one
// channel that reaches it anyway and it carried no address, so a scanned
// pairing had nowhere to send its accept and died in the holding space.

const MINTED_AT_DID = 'desktop-that-minted-it';

function mintedAtDiscovery(bool $canLook): PeerDiscovery
{
    return new class($canLook) implements PeerDiscovery
    {
        public function __construct(private readonly bool $canLook) {}

        public function reach(): LanDiscoveryReach
        {
            return $this->canLook ? LanDiscoveryReach::Available : LanDiscoveryReach::Unsupported;
        }

        /**
         * @return list<DiscoveredPeer>
         */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return [];
        }
    };
}

function mintedAtCourier(bool $canLook = false): LanPairingFrameCourier
{
    return new LanPairingFrameCourier(
        new LanPeerBrowser(app(HttpFactory::class), mintedAtDiscovery($canLook)),
    );
}

/**
 * @return array<string, mixed>
 */
function mintedAtFrame(): array
{
    return PairingFrame::buildResponderAccept('token-hash-abc', 'phone-did', str_repeat('a', 64), str_repeat('b', 64), 'iPhone');
}

function mintedAtTokenRow(?string $host, ?int $port): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('pairing_tokens')->insert([
        'user_id' => 1,
        'token_hash' => 'token-hash-abc',
        'initiator_device_id' => MINTED_AT_DID,
        'initiator_ed25519_pub_hex' => str_repeat('c', 64),
        'initiator_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'pending',
        'expires_at' => '2026-12-01T00:00:00Z',
        'created_at' => '2026-08-31T00:00:00Z',
        'initiator_lan_host' => $host,
        'initiator_lan_port' => $port,
    ]);
}

it('writes the address it can be reached at into the code it shows', function (): void {
    $uri = (new QrPayloadBuilder)->buildUri(
        'desktop-did',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'tok',
        'Desktop',
        null,
        new LanBootstrap('192.168.178.119', 51337),
    );

    expect($uri)->toContain('&host=192.168.178.119', '&port=51337');
});

it('leaves the address out when this device could not work out its own', function (): void {
    $uri = (new QrPayloadBuilder)->buildUri(
        'desktop-did',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'tok',
        'Desktop',
        null,
        new LanBootstrap(null, 51337),
    );

    expect($uri)->not->toContain('host=')
        ->and($uri)->not->toContain('port=');
});

it('reads the scanned address back off the row the responder seeded', function (): void {
    mintedAtTokenRow('192.168.178.119', 51337);

    $peer = (new ScannedPeerAddress(app(DatabaseManager::class), app(Clock::class)))->forTokenHash('token-hash-abc', MINTED_AT_DID);

    expect($peer)->not->toBeNull()
        ->and($peer?->host)->toBe('192.168.178.119')
        ->and($peer?->port)->toBe(51337)
        // Never Mdns: it came from a scan, and callers that ask whether an
        // address was learned from the network must keep getting false.
        ->and($peer?->discoveryMode->isFromNetwork())->toBeFalse();
});

it('answers with nothing when the row carries only half an address', function (): void {
    mintedAtTokenRow('192.168.178.119', null);

    expect((new ScannedPeerAddress(app(DatabaseManager::class), app(Clock::class)))->forTokenHash('token-hash-abc', MINTED_AT_DID))->toBeNull();
});

it('delivers to the address the code named on a device that cannot browse at all', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    $known = new DiscoveredPeer(MINTED_AT_DID, '192.168.178.119', 51337, DiscoveryMode::Manual);

    expect(mintedAtCourier(canLook: false)->deliver(MINTED_AT_DID, mintedAtFrame(), $known))->toBeTrue();

    Http::assertSent(static fn (mixed $request): bool => $request->url()
        === 'http://192.168.178.119:51337'.PairingFrameRequestHandler::FRAME_PATH);
});

it('still reports failure when the code named no address and nothing can be found', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    expect(mintedAtCourier(canLook: false)->deliver(MINTED_AT_DID, mintedAtFrame(), null))->toBeFalse();

    Http::assertNothingSent();
});
