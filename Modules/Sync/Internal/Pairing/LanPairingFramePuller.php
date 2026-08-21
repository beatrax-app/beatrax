<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Pairing\Concerns\BrowsesLanPeers;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
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
    use BrowsesLanPeers;

    // One answer cannot hand this device an unbounded amount of work: whoever
    // answers the browse chooses how many frames the reply carries.
    private const int MAX_FRAMES_APPLIED = 8;

    public function __construct(
        private HttpFactory $http,
        private PeerDiscovery $discovery,
        private PairingFrameApplier $applier,
        private DeviceKeySigner $signer,
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

        foreach ($this->eachConnectablePeer() as $peer) {
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
        $url = "http://{$host}:{$port}".PairingFramePullHandler::PULL_PATH;

        try {
            $response = $this->peerRequest()
                ->get($url, ['device' => $ownDeviceId, 'proof' => $proof]);

            if (! $response->successful()) {
                return [];
            }

            $body = $response->json();
        } catch (Throwable) {
            // Refused, timed out, or not answering with JSON. Nothing is
            // logged: the device id is in the request and pairing material in
            // the reply, and neither belongs in a log file.
            return [];
        }

        if (! is_array($body) || ! isset($body['frames']) || ! is_array($body['frames'])) {
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
