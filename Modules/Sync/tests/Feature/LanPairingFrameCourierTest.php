<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Internal\Pairing\LanPairingFrameCourier;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;

uses(RefreshDatabase::class);

// Two devices on one network had no way to finish a handshake: the frames had
// only the relay, so with none configured pairing on a home wifi failed outright
// with RelayRefusedException. This is the road that was missing.

/**
 * @param  list<DiscoveredPeer>  $peers
 */
function lanFrameCourier(array $peers = []): LanPairingFrameCourier
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

    return new LanPairingFrameCourier(new LanPeerBrowser(app(HttpFactory::class), $discovery));
}

function lanFramePeer(): DiscoveredPeer
{
    return new DiscoveredPeer('desktop-lan', '192.0.2.44', 51337, DiscoveryMode::Mdns);
}

/**
 * @return array<string, mixed>
 */
function acceptFrame(): array
{
    return PairingFrame::buildResponderAccept(
        str_repeat('d', 64),
        '11111111-2222-4333-8444-555555555555',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'Phone',
    );
}

it('reports success only when the peer applied the frame', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    expect(lanFrameCourier()->deliverTo(lanFramePeer(), acceptFrame()))->toBeTrue();
});

// A deferred confirm is valid but held until the peer's own human compares the
// words, so the peer very much does change its mind about it. Answering 204 said
// "applied" and the LAN road disagreed with the relay road about the same enum.
it('treats a frame the peer is holding as received, not as a road that failed', function (): void {
    Http::fake(['*' => Http::response('', 202)]);

    expect(lanFrameCourier()->deliverTo(lanFramePeer(), acceptFrame()))->toBeTrue();
});

it('posts the frame to the peer own sync port over plaintext http', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    lanFrameCourier()->deliverTo(lanFramePeer(), acceptFrame());

    Http::assertSent(fn ($request) => $request->url() === 'http://192.0.2.44:51337/pair/frame'
        && $request->method() === 'POST'
        && $request['type'] === 'PAIR_RESPONDER_ACCEPT');
});

// The peer answers 404 for every refusal it will never change its mind about.
// Treating that as delivered would strand the handshake: the caller must still
// be free to try the relay.
it('reports failure when the peer refuses the frame, so the relay still gets its turn', function (): void {
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    expect(lanFrameCourier()->deliverTo(lanFramePeer(), acceptFrame()))->toBeFalse();
});

// A 429 means the peer is there but is not taking this right now. It is not an
// acceptance, and anything that is not a 204 must not be read as one — including
// a stray 200 from something that is not our listener at all.
it('reports failure for any answer that is not the applied status', function (string $body, int $status): void {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(lanFrameCourier()->deliverTo(lanFramePeer(), acceptFrame()))->toBeFalse();
})->with([
    'rate limited' => ['{"error":"rate_limited"}', 429],
    'a 200 from something that is not the listener' => ['<html>hello</html>', 200],
    'server error' => ['', 500],
]);

it('reports failure when the peer cannot be reached at all', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(lanFrameCourier()->deliverTo(lanFramePeer(), acceptFrame()))->toBeFalse();
});

// Addressed by device id, never "whoever answers": the frame names the peer it
// is for. An empty id has no peer to name, so nothing is sent.
it('sends nothing when no peer device id was given', function (): void {
    Http::fake();

    expect(lanFrameCourier()->deliver('', acceptFrame()))->toBeFalse();

    Http::assertNothingSent();
});
