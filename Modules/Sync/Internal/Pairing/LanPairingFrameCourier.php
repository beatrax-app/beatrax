<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
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
    /** @var list<int> */
    private const array RECEIVED_STATUSES = [202, 204];

    public function __construct(private LanPeerBrowser $peers) {}

    // False means no peer on this network took it, so the caller still has the
    // relay and the holding space to try.
    /**
     * @param  array<string, mixed>  $frame
     * @param  ?DiscoveredPeer  $known  An address learned out of band — from
     *                                  the scanned QR — tried before any
     *                                  browse, because a device that cannot
     *                                  search the network has only this one.
     */
    public function deliver(string $peerDeviceId, array $frame, ?DiscoveredPeer $known = null): bool
    {
        if ($peerDeviceId === '') {
            return false;
        }

        foreach ($this->candidates($peerDeviceId, $known) as $peer) {
            if ($this->deliverTo($peer, $frame)) {
                return true;
            }
        }

        return false;
    }

    // The scanned address first, then whatever a browse turns up. A device
    // that cannot search the network holds the first and nothing else, and a
    // browse it cannot run must not be the only road tried.
    /**
     * @return iterable<int, DiscoveredPeer>
     */
    private function candidates(string $peerDeviceId, ?DiscoveredPeer $known): iterable
    {
        if ($known !== null && $known->isConnectable()) {
            yield $known;
        }

        yield from $this->peers->eachConnectablePeer(deviceId: $peerDeviceId);
    }

    // Public because the browse above reaches a real network: this is the seam
    // a test drives with a peer it chose rather than one it happened to find.
    /**
     * @param  array<string, mixed>  $frame
     */
    public function deliverTo(DiscoveredPeer $peer, array $frame): bool
    {
        $url = "http://{$peer->host}:{$peer->port}".PairingFrameRequestHandler::FRAME_PATH;

        try {
            $response = $this->peers->peerRequest()
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
