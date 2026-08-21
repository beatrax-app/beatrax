<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;
use Modules\Sync\Internal\Transport\PairingFrameRequestHandler;
use Throwable;

// Delivers a pairing frame to a peer on this network, so two devices that can
// see each other finish the handshake without an internet relay standing
// between them. The relay remains the road for devices that cannot.
//
// Addressed by device id, not by "whoever answers": the frame names the peer it
// is for, and mDNS publishes `did=` per advertisement, so this asks the peer
// that claims that id and no one else. A hostile responder claiming the id
// gains nothing — PAIR_CONFIRM is signed with a key it does not hold, and a
// PAIR_RESPONDER_ACCEPT it swallowed would simply never reach the real
// initiator, which is a failure to pair rather than a false pairing.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class LanPairingFrameCourier
{
    private const float BROWSE_TIMEOUT_SECONDS = 2.0;

    private const int CONNECT_TIMEOUT_SECONDS = 1;

    private const int REQUEST_TIMEOUT_SECONDS = 2;

    // One peer can advertise the same id many times over; bound the work so a
    // noisy or hostile network cannot turn one delivery into unbounded requests.
    private const int MAX_PEERS_TRIED = 4;

    public function __construct(
        private HttpFactory $http,
        private MulticastMdnsQuery $discovery,
    ) {}

    /**
     * Attempts LAN delivery to $peerDeviceId.
     *
     * @param  array<string, mixed>  $frame
     * @return bool true only when the peer accepted the frame; false means the
     *              caller should fall back to the relay.
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

        // The peer answers 204 when it applied the frame and 404 for every
        // refusal it will never change its mind about. Anything else — a 429,
        // a stray proxy, something that is not our listener — is not an
        // acceptance, so the relay still gets its turn.
        return $response->status() === 204;
    }
}
