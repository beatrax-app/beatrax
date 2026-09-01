<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\PairingFramePullHandler;
use SodiumException;
use Throwable;

// The phone's half of the return leg: it cannot be dialled, so it asks, proving
// with its own key that the frames are addressed to it. Whatever answers goes
// through the same applier every road uses (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#the-two-roads-and-why-the-lan-one-had-to-be-built
 */
final readonly class LanPairingFramePuller
{
    // One answer cannot hand this device an unbounded amount of work: whoever
    // answers the browse chooses how many frames the reply carries.
    private const int MAX_FRAMES_APPLIED = 8;

    public function __construct(
        private LanPeerBrowser $peers,
        private PairingFrameApplier $applier,
        private DeviceKeySigner $signer,
        private ScannedPeerAddress $scannedAddress,
    ) {}

    // Returns how many frames were applied, so a caller can tell "nothing was
    // waiting" from "something arrived and moved the handshake on".
    public function pullAndApply(int $userId, DeviceIdentityDto $identity): int
    {
        $ownDeviceId = $identity->deviceId;
        $proof = $this->proofFor($identity);

        if ($ownDeviceId === '' || $proof === null) {
            return 0;
        }

        $applied = 0;

        foreach ($this->peersToAsk($userId, $ownDeviceId) as $peer) {
            foreach ($this->framesFrom($peer->host, $peer->port, $ownDeviceId, $proof) as $frame) {
                if ($applied >= self::MAX_FRAMES_APPLIED) {
                    break 2;
                }

                if ($this->applier->apply($userId, $frame) === PairingFrameOutcome::Applied) {
                    $applied++;
                }
            }
        }

        return $applied;
    }

    // The address the scanned code named first, then a browse for one — the
    // same order the send leg takes. A phone cannot browse (see @link), so
    // without the scanned address its half of the return leg has no road at
    // all, and the confirm it is waiting for can never be collected.
    /**
     * @return iterable<int, DiscoveredPeer>
     */
    private function peersToAsk(int $userId, string $ownDeviceId): iterable
    {
        $asked = [];

        foreach ($this->scannedAddress->forCollector($userId, $ownDeviceId) as $peer) {
            if (! $peer->isConnectable()) {
                continue;
            }

            $asked[$peer->host.':'.$peer->port] = true;

            yield $peer;
        }

        foreach ($this->peers->eachConnectablePeer() as $peer) {
            if (! isset($asked[$peer->host.':'.$peer->port])) {
                yield $peer;
            }
        }
    }

    // Null when the secret key will not decode, which is a broken key-file
    // rather than a transient failure: without a proof there is nothing to ask
    // with, so the pull is skipped rather than sent unauthenticated.
    private function proofFor(DeviceIdentityDto $identity): ?string
    {
        try {
            return $this->signer->sign(
                PairingFrame::pullProofMessage($identity->deviceId),
                sodium_hex2bin($identity->ed25519SecretKeyHex),
            );
        } catch (SodiumException|\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function framesFrom(string $host, int $port, string $ownDeviceId, string $proof): array
    {
        $body = $this->askPeerForFrames($host, $port, $ownDeviceId, $proof);

        return $body === null ? [] : self::framesIn($body);
    }

    // Null covers every way this peer gave nothing to read, which the caller
    // treats as the empty answer it also gets from a peer holding no frames:
    // both mean "keep asking the next one".
    /**
     * @return array<mixed>|null
     */
    private function askPeerForFrames(string $host, int $port, string $ownDeviceId, string $proof): ?array
    {
        $url = "http://{$host}:{$port}".PairingFramePullHandler::PULL_PATH;

        try {
            $response = $this->peers->peerRequest()
                ->get($url, ['device' => $ownDeviceId, 'proof' => $proof]);

            $body = $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            // Refused, timed out, or not answering with JSON. Nothing is
            // logged: the device id is in the request and pairing material in
            // the reply, and neither belongs in a log file.
            return null;
        }

        return is_array($body) ? $body : null;
    }

    // The answer's shape is chosen by whoever answered, so a frame that is not
    // an object is dropped rather than carried to the applier as something it
    // has to defend against.
    /**
     * @param  array<mixed>  $body
     * @return list<array<string, mixed>>
     */
    private static function framesIn(array $body): array
    {
        if (! isset($body['frames']) || ! is_array($body['frames'])) {
            return [];
        }

        $frames = [];

        foreach ($body['frames'] as $frame) {
            if (is_array($frame)) {
                /** @var array<string, mixed> $frame */
                $frames[] = $frame;
            }
        }

        return $frames;
    }
}
