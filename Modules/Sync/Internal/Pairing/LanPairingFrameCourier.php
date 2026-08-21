<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Internal\Transport\PairingFrameRequestHandler;
use Throwable;

// Delivers a pairing frame to a peer on this network. Addressed by device id
// rather than to whoever answers: a responder spoofing the id cannot forge a
// signature, so it delays a pairing rather than capturing one (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#the-two-roads-and-why-the-lan-one-had-to-be-built
 */
final readonly class LanPairingFrameCourier
{
    private const float BROWSE_TIMEOUT_SECONDS = 2.0;

    private const int CONNECT_TIMEOUT_SECONDS = 1;

    private const int REQUEST_TIMEOUT_SECONDS = 2;

    // One peer can advertise the same id many times over; bound the work so a
    // noisy or hostile network cannot turn one delivery into unbounded requests.
    private const int MAX_PEERS_TRIED = 4;

    /** @var list<int> */
    private const array RECEIVED_STATUSES = [202, 204];

    public function __construct(
        private HttpFactory $http,
        private PeerDiscovery $discovery,
    ) {}

    // False means no peer on this network took it, so the caller still has the
    // relay and the holding space to try.
    /**
     * @param  array<string, mixed>  $frame
     */
    public function deliver(string $peerDeviceId, array $frame): bool
    {
        if ($peerDeviceId === '') {
            return false;
        }

        $tried = 0;

        foreach ($this->discovery->browse(MdnsAdvertiser::SERVICE_TYPE, self::BROWSE_TIMEOUT_SECONDS) as $peer) {
            if ($tried >= self::MAX_PEERS_TRIED) {
                break;
            }

            if (! $peer->isConnectable() || $peer->deviceId !== $peerDeviceId) {
                continue;
            }

            $tried++;

            if ($this->deliverTo($peer, $frame)) {
                return true;
            }
        }

        return false;
    }

    // Public for the same reason LanPairingOfferFetcher::offerFrom() is: the
    // browse above reaches a real network, so this is the seam a test can drive
    // with a peer it chose.
    /**
     * @param  array<string, mixed>  $frame
     */
    public function deliverTo(DiscoveredPeer $peer, array $frame): bool
    {
        // Plaintext http, exactly as the offer route is served: everything this
        // carries is either signed or worthless to an eavesdropper, and the
        // safety-number comparison — not the transport — is the trust gate.
        $url = "http://{$peer->host}:{$peer->port}".PairingFrameRequestHandler::FRAME_PATH;

        try {
            $response = $this->http->createPendingRequest()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->asJson()
                ->post($url, $frame);
        } catch (Throwable) {
            // Refused, timed out, or no route. Nothing is logged: the frame
            // carries a token hash and device identities, and neither belongs
            // in a log file.
            return false;
        }

        // 204 applied, 202 held until the peer's own human confirms, 404 a
        // refusal it will never change its mind about. Anything else — a 429, a
        // stray proxy, something that is not our listener — did not receive it,
        // so the relay still gets its turn (see @link).
        return in_array($response->status(), self::RECEIVED_STATUSES, true);
    }
}
